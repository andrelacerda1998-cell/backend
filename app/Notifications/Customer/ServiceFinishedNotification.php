<?php

namespace App\Notifications\Customer;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

class ServiceFinishedNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable;

    public function __construct(private readonly Service $service) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        return ExpoMessage::create(__('notifications.finishedService.title', [], $language))
            ->body(__('notifications.finishedService.description', [], $language).$this->service->serviceType->name)
            ->priority('high')
            ->playSound();
    }

    public function toArray($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        return [
            'title' => __('notifications.finishedService.title', [], $language),
            'body' => __('notifications.finishedService.description', [], $language).$this->service->serviceType->name,
        ];
    }
}
