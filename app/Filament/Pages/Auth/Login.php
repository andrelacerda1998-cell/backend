<?php

namespace App\Filament\Pages\Auth;

use App\Support\BackofficeTwoFactor;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Login do backoffice com 2FA por email (self-contained, sem o pacote abandonado
 * vormkracht10/filament-2fa).
 *
 * Fluxo em 2 passos DENTRO do mesmo componente Livewire (o do login, que já passa
 * o CSRF): passo 'credentials' valida email/password e envia o código; passo 'code'
 * verifica o código e só então autentica a sessão. Não há página/rota separada —
 * isso evita os 419 ("page expired") de uma página de desafio à parte com ->spa().
 */
class Login extends BaseLogin
{
    public string $step = 'credentials';

    protected function twoFactor(): BackofficeTwoFactor
    {
        return app(BackofficeTwoFactor::class);
    }

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());

            return;
        }

        // Se já há um desafio pendente (ex.: refresh), retoma o passo do código.
        if ($this->twoFactor()->hasPending()) {
            $this->step = 'code';
        }

        $this->form->fill();
    }

    /**
     * @return array<int|string, string|Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getEmailFormComponent()->visible(fn () => $this->step === 'credentials'),
                        $this->getPasswordFormComponent()->visible(fn () => $this->step === 'credentials'),
                        $this->getRememberFormComponent()->visible(fn () => $this->step === 'credentials'),
                        $this->getCodeFormComponent()->visible(fn () => $this->step === 'code'),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    protected function getCodeFormComponent(): Component
    {
        return TextInput::make('code')
            ->label(__('backoffice/two_factor.code_label'))
            ->required()
            ->autocomplete('one-time-code')
            ->extraInputAttributes(['inputmode' => 'numeric', 'tabindex' => 1]);
    }

    /**
     * Entry point do botão de submit (ambos os passos usam wire:submit="authenticate").
     */
    public function authenticate(): ?LoginResponse
    {
        return $this->step === 'code'
            ? $this->confirmCode()
            : $this->sendCode();
    }

    protected function sendCode(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();
        $credentials = $this->getCredentialsFromFormData($data);

        // Validar credenciais SEM iniciar sessão.
        $provider = Filament::auth()->getProvider();
        $user = $provider->retrieveByCredentials($credentials);

        if (! $user || ! $provider->validateCredentials($user, $credentials)) {
            $this->throwFailureValidationException();
        }

        // Só admin/super-admin entram no backoffice. Vendor/customer rejeitados aqui,
        // antes de qualquer código ser gerado ou enviado.
        if (($user instanceof FilamentUser) && ! $user->canAccessPanel(Filament::getCurrentPanel())) {
            $this->throwFailureValidationException();
        }

        $this->twoFactor()->start($user, (bool) ($data['remember'] ?? false));

        // Passa ao passo do código no MESMO componente (sem redirect → sem 419).
        $this->step = 'code';
        $this->form->fill();

        Notification::make()
            ->title(__('backoffice/two_factor.sent'))
            ->success()
            ->send();

        return null;
    }

    protected function confirmCode(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $twoFactor = $this->twoFactor();

        // Teto durável por CONTA (o rateLimit(5) acima é por IP). O modelo de ameaça do 2FA
        // assume a password já comprometida, por isso N IPs davam 5·N tentativas contra códigos
        // que rodam; e o teto de sessão do BackofficeTwoFactor limpava-se a cada novo login.
        // Persistido no cache → sobrevive ao clear() da sessão e a re-logins.
        $resolvedUser = $twoFactor->resolveUser();
        $accountKey = $resolvedUser ? '2fa:'.$resolvedUser->id : null;

        if ($accountKey && RateLimiter::tooManyAttempts($accountKey, 5)) {
            Notification::make()
                ->title(__('backoffice/two_factor.locked'))
                ->danger()
                ->send();

            $this->backToCredentials();

            return null;
        }

        if ($accountKey) {
            RateLimiter::hit($accountKey, 900); // 5 tentativas / 15 min por conta
        }

        $code = trim((string) ($this->form->getState()['code'] ?? ''));

        $result = $twoFactor->verify($code);

        if ($result === 'ok') {
            $user = $twoFactor->resolveUser();
            $remember = $twoFactor->remember();
            if ($accountKey) {
                RateLimiter::clear($accountKey);
            }
            $twoFactor->clear();

            if (! $user) {
                $this->backToCredentials();

                return null;
            }

            Filament::auth()->login($user, $remember);
            session()->regenerate();

            return app(LoginResponse::class);
        }

        // Estados sem recuperação (expirado / bloqueado / sessão perdida): volta às credenciais.
        if (in_array($result, ['expired', 'locked', 'none'], true)) {
            Notification::make()
                ->title(__('backoffice/two_factor.'.$result))
                ->danger()
                ->send();

            $this->backToCredentials();

            return null;
        }

        // Código errado: fica no passo do código.
        throw ValidationException::withMessages([
            'data.code' => __('backoffice/two_factor.invalid'),
        ]);
    }

    public function resendCode(): void
    {
        // Rate-limit por IP no reenvio (além do cooldown de 60s da sessão), para o botão de
        // reenvio não ser usado para inundar o email/rodar códigos.
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $twoFactor = $this->twoFactor();

        if ($twoFactor->resend()) {
            Notification::make()
                ->title(__('backoffice/two_factor.resent'))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('backoffice/two_factor.resend_cooldown', ['seconds' => $twoFactor->secondsUntilResend()]))
            ->warning()
            ->send();
    }

    protected function backToCredentials(): void
    {
        $this->twoFactor()->clear();
        $this->step = 'credentials';
        $this->form->fill();
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getAuthenticateFormAction()
                ->label(fn () => $this->step === 'code'
                    ? __('backoffice/two_factor.submit')
                    : __('filament-panels::pages/auth/login.form.actions.authenticate.label')),
            Action::make('resend')
                ->link()
                ->label(__('backoffice/two_factor.resend'))
                ->action('resendCode')
                ->visible(fn () => $this->step === 'code'),
        ];
    }

    public function getHeading(): string|Htmlable
    {
        return $this->step === 'code'
            ? __('backoffice/two_factor.heading')
            : parent::getHeading();
    }

    public function getSubheading(): string|Htmlable|null
    {
        return $this->step === 'code'
            ? __('backoffice/two_factor.subheading')
            : parent::getSubheading();
    }
}
