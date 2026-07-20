<?php

namespace App\Notifications\Vendor;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

class NewServiceAvailableNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Service $service) {}

    public function via($notifiable): array
    {
        return ['expo'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $title = __('notifications.newService.title', [], $language) .
            $this->service->serviceType->getTranslation('name', $language);
        if ($this->service->schedule) {
            $body = __('notifications.newService.description_schedule', [], $language);
        }else{
            $body = __('notifications.newService.description', [], $language);
        }

        return ExpoMessage::create($title)
            ->body($body)
            ->priority('high')
            ->playSound();
    }
}
