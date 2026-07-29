<?php

namespace App\Notifications\Customer;

use App\Models\Service;
use App\Models\ServiceExtra;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * A cobrança de um extra que o cliente aprovou falhou (cartão recusado, etc.).
 */
class ServiceExtraChargeFailedNotification extends Notification implements ShouldQueue
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

    private function itemLabel($language): string
    {
        $amount = number_format(((int) $this->extra->amount) / 100, 2, ',', '');

        if ($this->extra->type === 'time') {
            return __('notifications.serviceExtra.item.time', [
                'minutes' => (int) $this->extra->minutes,
                'amount' => $amount,
            ], $language);
        }

        return __('notifications.serviceExtra.item.part', [
            'description' => $this->extra->description ?? '',
            'amount' => $amount,
        ], $language);
    }

    private function title($language): string
    {
        return __('notifications.serviceExtra.chargeFailed.customer.title', [], $language);
    }

    private function body($language): string
    {
        return __('notifications.serviceExtra.chargeFailed.customer.description', [], $language).$this->itemLabel($language);
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $this->language($notifiable);

        return ExpoMessage::create($this->title($language))
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
            'title' => $this->title($language),
            'body' => $this->body($language),
            'service_id' => $this->service->id,
            'extra_id' => $this->extra->id,
            'open_type' => 'service',
            'open_id' => $this->service->id,
        ];
    }
}
