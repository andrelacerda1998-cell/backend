<?php

namespace App\Support;

use App\Notifications\Auth\BackofficeTwoFactorCode;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;

/**
 * 2FA por email para o backoffice.
 *
 * O código é gerado e enviado por email; o estado (código hasheado, expiração,
 * tentativas) vive na SESSÃO do browser que iniciou o login — o desafio corre na
 * mesma sessão, por isso não é preciso tabela/migração.
 */
class BackofficeTwoFactor
{
    public const SESSION_KEY = 'backoffice_2fa';

    protected function config(string $key, mixed $default = null): mixed
    {
        return config("backoffice-2fa.$key", $default);
    }

    /**
     * Inicia um desafio: gera código, guarda o estado na sessão e envia o email.
     */
    public function start(Authenticatable $user, bool $remember): void
    {
        $code = $this->generateCode();

        session()->put(self::SESSION_KEY, [
            'user_id' => $user->getAuthIdentifier(),
            'remember' => $remember,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) $this->config('ttl_minutes', 10))->getTimestamp(),
            'attempts' => 0,
            'sent_at' => now()->getTimestamp(),
        ]);

        $user->notify(new BackofficeTwoFactorCode($code));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pending(): ?array
    {
        $state = session(self::SESSION_KEY);

        return is_array($state) ? $state : null;
    }

    public function hasPending(): bool
    {
        return $this->pending() !== null;
    }

    public function remember(): bool
    {
        return (bool) ($this->pending()['remember'] ?? false);
    }

    /**
     * Segundos até ser permitido reenviar (0 = já permitido).
     */
    public function secondsUntilResend(): int
    {
        $state = $this->pending();

        if (! $state) {
            return 0;
        }

        $cooldown = (int) $this->config('resend_cooldown_seconds', 60);

        return max(0, $cooldown - (now()->getTimestamp() - (int) ($state['sent_at'] ?? 0)));
    }

    /**
     * Reenvia o código, se o cooldown permitir e o utilizador ainda resolver.
     */
    public function resend(): bool
    {
        $state = $this->pending();

        if (! $state || $this->secondsUntilResend() > 0) {
            return false;
        }

        $user = $this->resolveUser();

        if (! $user) {
            $this->clear();

            return false;
        }

        $code = $this->generateCode();

        $state['code_hash'] = Hash::make($code);
        $state['expires_at'] = now()->addMinutes((int) $this->config('ttl_minutes', 10))->getTimestamp();
        $state['sent_at'] = now()->getTimestamp();
        $state['attempts'] = 0;

        session()->put(self::SESSION_KEY, $state);

        $user->notify(new BackofficeTwoFactorCode($code));

        return true;
    }

    /**
     * Verifica o código submetido.
     *
     * @return string 'ok' | 'invalid' | 'expired' | 'locked' | 'none'
     */
    public function verify(string $code): string
    {
        $state = $this->pending();

        if (! $state) {
            return 'none';
        }

        if (now()->getTimestamp() > (int) ($state['expires_at'] ?? 0)) {
            $this->clear();

            return 'expired';
        }

        $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
        session()->put(self::SESSION_KEY, $state);

        if ($state['attempts'] > (int) $this->config('max_attempts', 5)) {
            $this->clear();

            return 'locked';
        }

        if (! Hash::check($code, (string) ($state['code_hash'] ?? ''))) {
            return 'invalid';
        }

        return 'ok';
    }

    public function resolveUser(): ?Authenticatable
    {
        $id = $this->pending()['user_id'] ?? null;

        if ($id === null) {
            return null;
        }

        return Filament::auth()->getProvider()->retrieveById($id);
    }

    public function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    protected function generateCode(): string
    {
        $length = (int) $this->config('code_length', 6);

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
