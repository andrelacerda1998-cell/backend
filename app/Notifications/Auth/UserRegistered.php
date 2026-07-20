<?php

namespace App\Notifications\Auth;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserRegistered extends Notification
{
    use Queueable;

    public $user;

    /**
     * Create a new notification instance.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
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
    public function toMail(object $notifiable): MailMessage
    {
        $userLanguage = $this->user->language ?? app()->getLocale() ?? config('app.fallback_locale');
        return (new MailMessage)
                    ->line(__('notifications.mail.userRegistered.line1', [], $userLanguage))
                    ->line(__('notifications.mail.userRegistered.line2', [], $userLanguage))
                    ->action(__('notifications.mail.userRegistered.action', [], $userLanguage), url('/email/verify/' . $this->user->id . '/' . $this->user->email_verified_token))
                    ->line(__('notifications.mail.userRegistered.line3', [], $userLanguage));
    }
}
