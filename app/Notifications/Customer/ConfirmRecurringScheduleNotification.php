<?php

namespace App\Notifications\Customer;

use App\Models\Schedule\Schedule;
use App\Notifications\Concerns\RoutesExpoToPushQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use NotificationChannels\Expo\ExpoMessage;

/**
 * Lembrete para confirmar e pagar a proxima ocorrencia de uma serie.
 *
 * Numa serie, cada servico e pago a parte e so acontece se o cliente confirmar.
 * Sem este aviso, a marcacao chegava ao dia por confirmar e caia sem ninguem
 * dar por isso -- e o tecnico tinha o horario reservado para nada.
 */
class ConfirmRecurringScheduleNotification extends Notification implements ShouldQueue
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

        return ExpoMessage::create(__('notifications.confirmRecurringSchedule.title', [], $language))
            ->body(__('notifications.confirmRecurringSchedule.description', [
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
            'title' => __('notifications.confirmRecurringSchedule.title', [], $language),
            'body' => __('notifications.confirmRecurringSchedule.description', [
                'service_type' => $serviceType,
                'day' => $day,
                'time' => $time,
            ], $language),
            'schedule_id' => $this->schedule->id,
        ];
    }
}
