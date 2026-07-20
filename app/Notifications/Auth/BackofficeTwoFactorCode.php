<?php

namespace App\Notifications\Auth;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Envia o código 2FA do backoffice por email.
 *
 * NÃO implementa ShouldQueue de propósito: o código tem de chegar de imediato e
 * não pode depender de um worker de fila estar a correr.
 */
class BackofficeTwoFactorCode extends Notification
{
    public function __construct(private string $code)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $minutes = (int) config('backoffice-2fa.ttl_minutes', 10);

        return (new MailMessage)
            ->subject(__('notifications.mail.twoFactorCode.subject', [], $language))
            ->greeting(__('notifications.mail.twoFactorCode.greetings', [], $language))
            ->line(__('notifications.mail.twoFactorCode.line1', [], $language))
            ->line('# '.$this->code)
            ->line(__('notifications.mail.twoFactorCode.line2', ['minutes' => $minutes], $language))
            ->line(__('notifications.mail.twoFactorCode.line3', [], $language))
            ->salutation(__('notifications.mail.twoFactorCode.salutation', [], $language));
    }
}
