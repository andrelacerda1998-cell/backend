<?php

namespace App\Notifications\Customer;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * O técnico está à porta e não encontra o cliente.
 *
 * Muitas vezes é uma campainha que não toca ou um andar errado: avisar o
 * cliente na hora resolve mais casos do que qualquer regra. Abre o serviço,
 * onde estão o telefone e o chat do técnico.
 */
class VendorCantFindCustomerNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable;

    public function __construct(private readonly Service $service) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        [$title, $body] = $this->textos($notifiable);

        return ExpoMessage::create($title)
            ->body($body)
            ->priority('high')
            ->playSound()
            ->data(['open_type' => 'service', 'open_id' => $this->service->id]);
    }

    public function toArray($notifiable): array
    {
        [$title, $body] = $this->textos($notifiable);

        return ['title' => $title, 'body' => $body, 'open_type' => 'service', 'open_id' => $this->service->id];
    }

    /** @return array{0: string, 1: string} */
    private function textos($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $nome = $this->service->loadMissing('vendor.user')->vendor?->user?->name ?? '';

        return [
            __('notifications.vendorCantFindCustomer.title', [], $language),
            __('notifications.vendorCantFindCustomer.description', ['vendor_name' => $nome], $language),
        ];
    }
}
