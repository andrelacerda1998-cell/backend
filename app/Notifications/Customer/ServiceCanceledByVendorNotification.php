<?php

namespace App\Notifications\Customer;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

class ServiceCanceledByVendorNotification extends Notification implements ShouldQueue
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
        $service = $this->service->loadMissing(['serviceType', 'vendor.user']);
        $serviceType = $service->serviceType?->getTranslation('name', $language) ?? '';
        $vendorName = $service->vendor?->user?->name ?? '';

        $title = __('notifications.serviceCanceledByVendor.title', [], $language);
        $body = __('notifications.serviceCanceledByVendor.description', [
            'vendor_name' => $vendorName,
            'service_type' => $serviceType,
        ], $language);

        return ExpoMessage::create($title)
            ->body($body)
            ->priority('high')
            ->playSound();
    }

    public function toArray($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $service = $this->service->loadMissing(['serviceType', 'vendor.user']);
        $serviceType = $service->serviceType?->getTranslation('name', $language) ?? '';
        $vendorName = $service->vendor?->user?->name ?? '';

        return [
            'title' => __('notifications.serviceCanceledByVendor.title', [], $language),
            'body' => __('notifications.serviceCanceledByVendor.description', [
                'vendor_name' => $vendorName,
                'service_type' => $serviceType,
            ], $language),
            'service_id' => $this->service->id,
        ];
    }
}
