<?php

namespace App\Notifications\Vendor;

use App\Models\Schedule\Schedule;
use App\Notifications\Concerns\RoutesExpoToPushQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use NotificationChannels\Expo\ExpoMessage;

/**
 * Lembra o tecnico, 72h antes, de que tem um servico marcado — e pede-lhe que
 * confirme.
 *
 * Um agendamento aceite ha duas semanas e facil de esquecer, e quem fica a
 * espera em casa e o cliente. A confirmacao a 72h ainda deixa tempo de arranjar
 * outro tecnico se ele ja nao puder.
 */
class ScheduleAttendanceReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RoutesExpoToPushQueue;

    public function __construct(private readonly Schedule $schedule) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    private function payload($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $schedule = $this->schedule->loadMissing(['serviceType']);

        return [
            'language' => $language,
            'service_type' => $schedule->serviceType?->getTranslation('name', $language) ?? '',
            'day' => $schedule->scheduled_day
                ? Carbon::parse($schedule->scheduled_day)->format('d/m')
                : '',
            'time' => $schedule->scheduled_time_start
                ? substr((string) $schedule->scheduled_time_start, 0, 5)
                : '',
        ];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        ['language' => $language, 'service_type' => $serviceType, 'day' => $day, 'time' => $time] = $this->payload($notifiable);

        return ExpoMessage::create(__('notifications.scheduleAttendanceReminder.title', [], $language))
            ->body(__('notifications.scheduleAttendanceReminder.description', [
                'service_type' => $serviceType,
                'day' => $day,
                'time' => $time,
            ], $language))
            ->priority('high')
            ->playSound();
    }

    public function toArray($notifiable): array
    {
        ['language' => $language, 'service_type' => $serviceType, 'day' => $day, 'time' => $time] = $this->payload($notifiable);

        return [
            'title' => __('notifications.scheduleAttendanceReminder.title', [], $language),
            'body' => __('notifications.scheduleAttendanceReminder.description', [
                'service_type' => $serviceType,
                'day' => $day,
                'time' => $time,
            ], $language),
            'schedule_id' => $this->schedule->id,
            // A app do técnico usa isto para mostrar o botão de confirmar.
            'action' => 'confirm_attendance',
        ];
    }
}
