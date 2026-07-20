<?php

namespace App\Notifications\Vendor;

use App\Models\Schedule\Schedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

class ScheduleReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Schedule $schedule) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $schedule = $this->schedule->loadMissing(['serviceType', 'customer']);
        $serviceType = $schedule->serviceType?->getTranslation('name', $language) ?? '';
        $customerName = $schedule->customer?->name ?? '';

        $title = __('notifications.scheduleReminder.vendor.title', [], $language);
        $body = __('notifications.scheduleReminder.vendor.description', [
            'customer_name' => $customerName,
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
        $schedule = $this->schedule->loadMissing(['serviceType', 'customer']);
        $serviceType = $schedule->serviceType?->getTranslation('name', $language) ?? '';
        $customerName = $schedule->customer?->name ?? '';

        return [
            'title' => __('notifications.scheduleReminder.vendor.title', [], $language),
            'body' => __('notifications.scheduleReminder.vendor.description', [
                'customer_name' => $customerName,
                'service_type' => $serviceType,
            ], $language),
            'schedule_id' => $this->schedule->id,
        ];
    }
}
