<?php

namespace App\Notifications\Vendor;

use App\Models\Service;
use App\Notifications\Concerns\RoutesExpoToPushQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * "Faltaste a um serviço e foste penalizado."
 *
 * DE PROPÓSITO sem RespectsVendorPreference, como o nudge de não-comparência:
 * isto não é um lembrete que se possa desligar nas definições — é dinheiro que
 * saiu da carteira dele. Descobrir um débito sem explicação semanas mais tarde é
 * como um problema operacional se transforma numa reclamação.
 */
class NoShowPenaltyNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RoutesExpoToPushQueue;

    public function __construct(
        private readonly Service $service,
        /** Em cêntimos. */
        private readonly int $penalty,
    ) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $service = $this->service->loadMissing('serviceType');
        $serviceType = $service->serviceType?->getTranslation('name', $language) ?? '';

        return ExpoMessage::create(__('notifications.noShowPenalty.title', [], $language))
            ->body(__('notifications.noShowPenalty.description', [
                'service_type' => $serviceType,
                'amount' => number_format($this->penalty / 100, 2, ',', ' '),
            ], $language))
            ->priority('high')
            ->playSound()
            ->data([
                'open_type' => 'service',
                'open_id' => $this->service->id,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'service_id' => $this->service->id,
            'penalty' => $this->penalty,
            'reason' => 'vendor_no_show',
        ];
    }
}
