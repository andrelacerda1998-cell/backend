<?php

namespace App\Notifications\Customer;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

class ServiceVendorArrivedNotification extends Notification implements ShouldQueue
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
        return ExpoMessage::create(__('notifications.vendorArrivedService.title', [], $language))
            ->body($this->service->vendor->username.' '.__('notifications.vendorArrivedService.description', [], $language))
            ->priority('high')
            ->playSound();
    }

    public function toArray($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        return [
            'title' => __('notifications.vendorArrivedService.title', [], $language),
            'body' => $this->service->vendor->username.' '.__('notifications.vendorArrivedService.description', [], $language),
        ];
    }
}
