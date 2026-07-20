<?php

namespace App\Notifications\Customer\Mbway;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

class PaymentRefusedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Service $service) {}

    public function via($notifiable): array
    {
        return ['expo'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $this->service->customer->language ?? $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        return ExpoMessage::create(__('notifications.mbway.paymentRefused.title', [], $language))
            ->body(__('notifications.mbway.paymentRefused.description', [], $language).$this->service->serviceType->name)
            ->priority('high')
            ->playSound();
    }
}
