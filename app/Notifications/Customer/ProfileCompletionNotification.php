<?php

namespace App\Notifications\Customer;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

class ProfileCompletionNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable;

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return ExpoMessage::create(__('notifications.profileCompletion.title', [], $language))
            ->body(__('notifications.profileCompletion.description', [], $language))
            ->priority('high')
            ->data([
                'type' => 'profile_completion',
            ]);
    }

    public function toArray($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return [
            'title' => __('notifications.profileCompletion.title', [], $language),
            'body' => __('notifications.profileCompletion.description', [], $language),
            'type' => 'profile_completion',
        ];
    }
}
