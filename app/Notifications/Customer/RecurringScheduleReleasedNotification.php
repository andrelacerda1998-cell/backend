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
 * O horario de uma ocorrencia de serie foi libertado por falta de pagamento.
 *
 * Sem este aviso, a marcacao desaparecia da lista sem explicacao — e havia
 * quem ficasse em casa a espera de um tecnico que nunca foi marcado.
 */
class RecurringScheduleReleasedNotification extends Notification implements ShouldQueue
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
        ];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        ['language' => $language, 'service_type' => $serviceType, 'day' => $day] = $this->payload($notifiable);

        return ExpoMessage::create(__('notifications.recurringScheduleReleased.title', [], $language))
            ->body(__('notifications.recurringScheduleReleased.description', [
                'service_type' => $serviceType,
                'day' => $day,
            ], $language))
            ->priority('high')
            ->playSound();
    }

    public function toArray($notifiable): array
    {
        ['language' => $language, 'service_type' => $serviceType, 'day' => $day] = $this->payload($notifiable);

        return [
            'title' => __('notifications.recurringScheduleReleased.title', [], $language),
            'body' => __('notifications.recurringScheduleReleased.description', [
                'service_type' => $serviceType,
                'day' => $day,
            ], $language),
            'schedule_id' => $this->schedule->id,
        ];
    }
}
