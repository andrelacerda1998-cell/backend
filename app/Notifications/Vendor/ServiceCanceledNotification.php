<?php

namespace App\Notifications\Vendor;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

class ServiceCanceledNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Service $service) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $serviceType = $this->service->loadMissing('serviceType')->serviceType?->getTranslation('name', $language) ?? '';

        return ExpoMessage::create(__('notifications.canceledService.title', [], $language))
            ->body(__('notifications.canceledService.description', [], $language).$serviceType)
            ->priority('high')
            ->playSound();
    }

    public function toArray($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $serviceType = $this->service->loadMissing('serviceType')->serviceType?->getTranslation('name', $language) ?? '';

        return [
            'title' => __('notifications.canceledService.title', [], $language),
            'body' => __('notifications.canceledService.description', [], $language).$serviceType,
            'service_id' => $this->service->id,
        ];
    }
}
