<?php

namespace App\Notifications\Vendor;

use App\Models\Schedule\Schedule;
use App\Notifications\Concerns\RespectsVendorPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use NotificationChannels\Expo\ExpoMessage;

class NewScheduledServiceNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable, RespectsVendorPreference;

    public function __construct(private readonly Schedule $schedule) {}

    public function via($notifiable): array
    {
        return $this->applyVendorPreference($notifiable, 'new_requests', ['expo']);
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $schedule = $this->schedule->loadMissing('serviceType');
        $when = $this->formatWhen($schedule, $language);
        $serviceType = $schedule->serviceType?->getTranslation('name', $language) ?? '';

        $title = __('notifications.scheduledService.title', ['when' => $when], $language);
        $body = __('notifications.scheduledService.description', ['service_type' => $serviceType], $language);

        return ExpoMessage::create($title)
            ->body($body)
            ->priority('high')
            ->playSound()
            ->channelId('requests')
            ->data([
                'open_type' => 'request',
                'open_id' => $schedule->service_id ?? $schedule->id,
            ]);
    }

    private function formatWhen(Schedule $schedule, string $language): string
    {
        $scheduledDay = Carbon::parse($schedule->scheduled_day);

        if ($scheduledDay->isTomorrow()) {
            return __('notifications.scheduledService.when.tomorrow', [], $language);
        }

        return __('notifications.scheduledService.when.date', ['day' => $scheduledDay->day], $language);
    }
}
