<?php

namespace App\Notifications\Vendor;

use App\Models\Service;
use App\Models\User;
use App\Notifications\Concerns\RespectsVendorPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

class NewMessageNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable, RespectsVendorPreference;

    public function __construct(private readonly Service $service) {}

    public function via($notifiable): array
    {
        return $this->applyVendorPreference($notifiable, 'messages', ['expo']);
    }

    public function toExpo(User $notifiable): ExpoMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        return ExpoMessage::create(__('notifications.newMessage.title', [], $language))
            ->body(__('notifications.newMessage.description', [], $language).$this->service->serviceType->name)
            ->priority('high')
            ->playSound()
            ->data([
                'open_type' => 'service',
                'open_id' => $this->service->id,
            ]);
    }
}
