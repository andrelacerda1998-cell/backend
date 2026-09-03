<?php

namespace App\Notifications\Customer;

use App\Models\Schedule\Schedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * Avisa o cliente de que o serviço agendado ainda não arrancou depois da hora
 * (segunda etapa da deteção de não-comparência, T+15min). Tom tranquilizador:
 * reconhece o atraso e diz que estamos a tratar — sem prometer nem alarmar.
 */
class ScheduleDelayedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Schedule $schedule,
        private readonly string $time,
    ) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $schedule = $this->schedule->loadMissing('serviceType');
        $serviceType = $schedule->serviceType?->getTranslation('name', $language) ?? '';

        $message = ExpoMessage::create(__('notifications.noShow.customer.title', [], $language))
            ->body(__('notifications.noShow.customer.description', [
                'service_type' => $serviceType,
                'time' => $this->time,
            ], $language))
            ->priority('high')
            ->playSound();

        if ($schedule->service_id) {
            $message->data([
                'open_type' => 'service',
                'open_id' => $schedule->service_id,
            ]);
        }

        return $message;
    }

    public function toArray($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $schedule = $this->schedule->loadMissing('serviceType');
        $serviceType = $schedule->serviceType?->getTranslation('name', $language) ?? '';

        return [
            'type' => 'no_show_customer',
            'service_id' => $schedule->service_id,
            'title' => __('notifications.noShow.customer.title', [], $language),
            'body' => __('notifications.noShow.customer.description', [
                'service_type' => $serviceType,
                'time' => $this->time,
            ], $language),
        ];
    }
}
