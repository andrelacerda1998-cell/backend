<?php

namespace App\Notifications\Auth;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordReset extends Notification
{
    use Queueable;

    public $token;
    public $email;
    public $isVendor;

    /**
     * Create a new notification instance.
     */
    public function __construct($token, $email, $isVendor)
    {
        $this->token = $token;
        $this->email = $email;
        $this->isVendor = $isVendor;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(): MailMessage
    {
        $userLanguage = User::where('email', $this->email)->first()->language ?? app()->getLocale() ?? config('app.fallback_locale');
        return (new MailMessage)
                    ->subject(__('notifications.mail.passwordReset.subject', [], $userLanguage))
                    ->greeting(__('notifications.mail.passwordReset.greetings', [], $userLanguage))
                    ->line(__('notifications.mail.passwordReset.line1', [], $userLanguage))
                    ->action(__('notifications.mail.passwordReset.action', [], $userLanguage), url('/reset-password/' . $this->token . '?email=' . $this->email))
                    ->line(__('notifications.mail.passwordReset.line2', [], $userLanguage))
                    ->salutation(__('notifications.mail.passwordReset.salutation', [], $userLanguage));
    }
}
