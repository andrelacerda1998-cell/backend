<?php

namespace App\Notifications\Vendor;

use App\Models\Service;
use App\Models\ServiceExtra;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * O cliente respondeu a um pedido de tempo extra / peça: aprovado ou recusado.
 * Puramente informativo — não altera valores nem pagamentos.
 */
class ServiceExtraResolvedNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable;

    public function __construct(
        private readonly Service $service,
        private readonly ServiceExtra $extra,
        private readonly bool $approved,
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
        return $this->approved
            ? __('notifications.serviceExtra.approved.title', [], $language)
            : __('notifications.serviceExtra.rejected.title', [], $language);
    }

    private function body($language): string
    {
        $item = $this->itemLabel($language);

        if ($this->approved) {
            return __('notifications.serviceExtra.approved.description', [], $language).$item;
        }

        $body = __('notifications.serviceExtra.rejected.description', [], $language).$item;

        if (filled($this->extra->rejection_reason)) {
            $body .= ' '.__('notifications.serviceExtra.rejected.reason', [
                'reason' => $this->extra->rejection_reason,
            ], $language);
        }

        return $body;
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
