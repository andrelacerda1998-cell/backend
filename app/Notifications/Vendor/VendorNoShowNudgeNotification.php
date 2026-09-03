<?php

namespace App\Notifications\Vendor;

use App\Models\Schedule\Schedule;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * "Ainda não saíste?" — nudge ao técnico quando a hora do agendamento passou e o
 * serviço continua em SCHEDULED (nunca marcou "A caminho"). É a primeira etapa da
 * deteção de não-comparência (T+10min).
 *
 * DE PROPÓSITO sem RespectsVendorPreference: isto é operacional/urgente (um cliente
 * está à espera agora), não um lembrete opcional — não deve ser silenciável.
 */
class VendorNoShowNudgeNotification extends Notification implements ShouldQueue
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
        $schedule = $this->schedule->loadMissing(['serviceType', 'customer']);
        $serviceType = $schedule->serviceType?->getTranslation('name', $language) ?? '';

        $message = ExpoMessage::create(__('notifications.noShow.vendor.title', [], $language))
            ->body(__('notifications.noShow.vendor.description', [
                'service_type' => $serviceType,
                'customer_name' => $schedule->customer?->name ?? '',
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
        $schedule = $this->schedule->loadMissing(['serviceType', 'customer']);
        $serviceType = $schedule->serviceType?->getTranslation('name', $language) ?? '';

        return [
            'type' => 'no_show_vendor',
            'service_id' => $schedule->service_id,
            'title' => __('notifications.noShow.vendor.title', [], $language),
            'body' => __('notifications.noShow.vendor.description', [
                'service_type' => $serviceType,
                'customer_name' => $schedule->customer?->name ?? '',
                'time' => $this->time,
            ], $language),
        ];
    }
}
