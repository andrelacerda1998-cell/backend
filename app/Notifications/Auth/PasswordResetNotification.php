<?php

namespace App\Notifications\Auth;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private string $token)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        return (new MailMessage)
            ->subject(__('notifications.mail.passwordReset.subject', [], $language))
            ->greeting(__('notifications.mail.passwordReset.greetings', [], $language))
            ->line(__('notifications.mail.passwordReset.line1', [], $language))
            ->action(__('notifications.mail.passwordReset.action', [], $language), url(config('app.url').route('password.reset', $this->token, false)))
            ->line(__('notifications.mail.passwordReset.line2', [], $language))
            ->salutation(__('notifications.mail.passwordReset.salutation', [], $language));
    }
}
