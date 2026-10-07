<?php

namespace App\Notifications\Customer;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * O técnico cancelou.
 *
 * Com `$reaberto`, o pedido já está outra vez à procura de outro técnico (ver
 * ReabrirPedidoAposCancelamento): a mensagem diz isso, e tocar nela abre o
 * ecrã de escolha do pedido novo. Sem ele, é o aviso de sempre.
 */
class ServiceCanceledByVendorNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable;

    public function __construct(
        private readonly Service $service,
        private readonly ?Service $reaberto = null,
    ) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        [$title, $body] = $this->textos($notifiable);

        $message = ExpoMessage::create($title)
            ->body($body)
            ->priority('high')
            ->playSound();

        if ($this->reaberto) {
            $message->data([
                'open_type' => 'matching',
                'open_id' => $this->reaberto->id,
            ]);
        }

        return $message;
    }

    public function toArray($notifiable): array
    {
        [$title, $body] = $this->textos($notifiable);

        return array_filter([
            'title' => $title,
            'body' => $body,
            'service_id' => $this->service->id,
            'open_type' => $this->reaberto ? 'matching' : null,
            'open_id' => $this->reaberto?->id,
        ], fn ($v) => $v !== null);
    }

    /** @return array{0: string, 1: string} */
    private function textos($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $service = $this->service->loadMissing(['serviceType', 'vendor.user']);
        $serviceType = $service->serviceType?->getTranslation('name', $language) ?? '';
        $vendorName = $service->vendor?->user?->name ?? '';
        $chave = $this->reaberto ? 'serviceCanceledByVendorReopened' : 'serviceCanceledByVendor';

        return [
            __("notifications.{$chave}.title", [], $language),
            __("notifications.{$chave}.description", [
                'vendor_name' => $vendorName,
                'service_type' => $serviceType,
            ], $language),
        ];
    }
}
