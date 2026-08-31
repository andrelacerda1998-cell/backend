<?php

namespace App\Services\Common;

use App\Enums\SmsType;
use App\Exceptions\Api\User\ToManyValidationsCode;
use App\Models\Auth\PhoneNumberValidationCode;
use App\Models\User;
use App\Notifications\Auth\PhoneNumberValidationNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class PhoneLoginSmsService
{
    public function sendCode(string $phoneNumber): array
    {
        $user = $this->findUserByPhone($phoneNumber);

        // Número desconhecido recebe código na mesma: é assim que um cliente NOVO
        // se regista (a conta nasce ao verificar, em PhoneLoginVerifyController).
        // Recusar aqui obrigava a um segundo fluxo de registo — e dizia "credenciais
        // erradas" a quem nunca teve conta.
        //
        // Não é enumeração de utilizadores: a resposta é igual exista ou não conta,
        // por isso ninguém descobre por aqui quem é cliente. O anti-abuso continua
        // a ser o mesmo (janela de 5 min por número + throttle por número e IP).
        $canonicalPhone = $user?->phone_number ?? self::normalizePhoneNumber($phoneNumber);

        $existingRecent = PhoneNumberValidationCode::where('phone_number', $canonicalPhone)
            ->where('type', SmsType::Login)
            ->where('created_at', '>', now()->subMinutes(5))
            ->exists();

        if ($existingRecent) {
            throw new ToManyValidationsCode;
        }

        $code = random_int(100000, 999999);

        PhoneNumberValidationCode::create([
            'user_id' => null,
            'phone_number' => $canonicalPhone,
            'code' => $code,
            'type' => SmsType::Login,
        ]);

        if (config('app.MOCK_SMS') && ! app()->isProduction()) {
            return ['success' => true, 'mock_code' => $code];
        }

        // Sem conta ainda (registo por telemóvel) não há a quem chamar notify():
        // envia-se para o número, como no fluxo de convidado.
        if ($user) {
            $user->notify(new PhoneNumberValidationNotification($code));
        } else {
            Notification::route('twilio', $canonicalPhone)
                ->notify(new PhoneNumberValidationNotification($code));
        }

        return ['success' => true];
    }

    public function verifyCode(string $phoneNumber, string $code): ?User
    {
        $user = $this->findUserByPhone($phoneNumber);
        $canonicalPhone = $user?->phone_number ?? $phoneNumber;

        // Anti-brute-force por número (ver GuestVerifyOtpController). Após 5 falhas devolve
        // null (código inválido) até expirar/pedir novo. Fail-open com default 0.
        $failKey = "otp_login_fail:{$canonicalPhone}";

        if (Cache::get($failKey, 0) >= 5) {
            return null;
        }

        $validation = PhoneNumberValidationCode::where('phone_number', $canonicalPhone)
            ->where('type', SmsType::Login)
            ->where('created_at', '>', now()->subMinutes(5))
            ->where('code', $code)
            ->first();

        if (! $validation) {
            Cache::put($failKey, Cache::get($failKey, 0) + 1, now()->addMinutes(10));

            return null;
        }

        Cache::forget($failKey);

        if (! $user) {
            $user = User::where('phone_number', $canonicalPhone)->first();
        }

        // Número novo: o código acabou de provar que o telemóvel é desta pessoa,
        // e é essa prova que cria a conta. Sem isto, um cliente novo recebia SMS,
        // acertava no código e ainda assim ouvia "credenciais erradas".
        $user = $this->findOrCreateByPhone($canonicalPhone);

        if (! $user) {
            return null;
        }

        $validation->delete();

        return $user;
    }

    /**
     * O utilizador deste número, criando-o se ainda não existir.
     *
     * Chamado quando o número JÁ foi provado (código correto, ou MOCK_SMS em
     * desenvolvimento). Nasce sem email nem password — o email é pedido quando
     * faz falta, na primeira fatura.
     *
     * O lock serializa pedidos simultâneos: sem ele, dois toques rápidos no
     * mesmo código criavam duas contas para o mesmo número.
     */
    public function findOrCreateByPhone(string $phoneNumber): ?User
    {
        $canonicalPhone = self::normalizePhoneNumber($phoneNumber);
        $user = $this->findUserByPhone($phoneNumber);

        if (! $user) {
            $lock = Cache::lock('phone_register:'.$canonicalPhone, 10);

            if (! $lock->get()) {
                return null;
            }

            try {
                $user = $this->findUserByPhone($phoneNumber)
                    ?? User::create([
                        'phone_number' => $canonicalPhone,
                        'phone_number_verified_at' => now(),
                        'email' => null,
                        'password' => null,
                        'language' => app()->getLocale(),
                    ]);
            } finally {
                $lock->release();
            }
        }

        if ($user && ! $user->phone_number_verified_at) {
            $user->forceFill(['phone_number_verified_at' => now()])->save();
        }

        return $user;
    }

    public function findUserByPhone(string $phoneNumber, bool $withTrashed = false): ?User
    {
        $normalized = self::normalizePhoneNumber($phoneNumber);
        $stripped = preg_replace('/^\+351-?/', '', $normalized);

        $candidates = array_unique([
            $phoneNumber,
            $normalized,
            '+351-'.$stripped,
            '+351'.$stripped,
            $stripped,
        ]);

        $query = User::whereIn('phone_number', $candidates);

        if ($withTrashed) {
            // Conta ativa tem prioridade sobre uma soft-deleted com o mesmo número.
            $query->withTrashed()->orderByRaw('deleted_at IS NOT NULL');
        }

        // Determinismo com duplicados existentes: entra sempre a conta mais antiga (a original).
        return $query->orderBy('id')->first();
    }

    /**
     * Formato canónico +351XXXXXXXXX: remove espaços/traços/pontos e
     * converte os prefixos 00351/351, ou 9 dígitos locais, para +351.
     * Números estrangeiros (outro indicativo +) ficam intactos.
     */
    public static function normalizePhoneNumber(string $phoneNumber): string
    {
        $phone = preg_replace('/[\s\-\.]+/', '', trim($phoneNumber));

        if (str_starts_with($phone, '00351')) {
            return '+351'.substr($phone, 5);
        }

        if (str_starts_with($phone, '351') && strlen($phone) === 12) {
            return '+'.$phone;
        }

        if (! str_starts_with($phone, '+') && strlen($phone) === 9) {
            return '+351'.$phone;
        }

        return $phone;
    }
}
