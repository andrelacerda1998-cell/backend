<?php

namespace App\Notifications\Customer;

use App\Models\Service;
use App\Models\ServiceExtra;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * O técnico pediu tempo extra ou uma peça durante o serviço.
 * O cliente tem de aprovar/recusar na app — nada é cobrado por esta notificação.
 */
class ServiceExtraRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Service $service,
        private readonly ServiceExtra $extra,
    ) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    private function language($notifiable): string
    {
        return $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
    }

    private function body($language): string
    {
        $serviceType = $this->service->loadMissing('serviceType')->serviceType?->getTranslation('name', $language) ?? '';
        $amount = number_format(((int) $this->extra->amount) / 100, 2, ',', '');

        if ($this->extra->type === 'time') {
            return __('notifications.serviceExtra.requested.time', [
                'minutes' => (int) $this->extra->minutes,
                'amount' => $amount,
                'service_type' => $serviceType,
            ], $language);
        }

        return __('notifications.serviceExtra.requested.part', [
            'description' => $this->extra->description ?? '',
            'amount' => $amount,
            'service_type' => $serviceType,
        ], $language);
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $this->language($notifiable);

        return ExpoMessage::create(__('notifications.serviceExtra.requested.title', [], $language))
            ->body($this->body($language))
            ->priority('high')
            ->playSound()
            ->data([
                'open_type' => 'service',
                'open_id' => $this->service->id,
            ]);
    }

    public function toArray($notifiable): array
    {
        $language = $this->language($notifiable);

        return [
            'title' => __('notifications.serviceExtra.requested.title', [], $language),
            'body' => $this->body($language),
            'service_id' => $this->service->id,
            'extra_id' => $this->extra->id,
            'open_type' => 'service',
            'open_id' => $this->service->id,
        ];
    }
}
