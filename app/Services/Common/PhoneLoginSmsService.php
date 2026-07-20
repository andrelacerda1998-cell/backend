<?php

namespace App\Services\Common;

use App\Enums\SmsType;
use App\Exceptions\Api\User\ToManyValidationsCode;
use App\Exceptions\Api\User\WrongCredentials;
use App\Models\Auth\PhoneNumberValidationCode;
use App\Models\User;
use App\Notifications\Auth\PhoneNumberValidationNotification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class PhoneLoginSmsService
{
    public function sendCode(string $phoneNumber): array
    {
        $user = $this->findUserByPhone($phoneNumber);

        if (! $user) {
            throw new WrongCredentials;
        }

        $canonicalPhone = $user->phone_number;

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

        $user->notify(new PhoneNumberValidationNotification($code));

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

        if (! $user) {
            return null;
        }

        $validation->delete();

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
