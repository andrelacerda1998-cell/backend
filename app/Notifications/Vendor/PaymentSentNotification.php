<?php

namespace App\Notifications\Vendor;

use App\Notifications\Concerns\RespectsVendorPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

class PaymentSentNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsVendorPreference;

    public function __construct(private string $amount) {}

    public function via($notifiable): array
    {
        return $this->applyVendorPreference($notifiable, 'payments', ['expo', 'database']);
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return ExpoMessage::create(__('notifications.paymentSent.title', [], $language))
            ->body(__('notifications.paymentSent.description', ['value' => $this->amount], $language))
            ->priority('high')
            ->playSound();
    }

    public function toArray($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return [
            'title' => __('notifications.paymentSent.title', [], $language),
            'body' => __('notifications.paymentSent.description', ['value' => $this->amount], $language),
        ];
    }
}
