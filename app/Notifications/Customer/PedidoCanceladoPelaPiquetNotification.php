<?php

namespace App\Notifications\Customer;

use App\Models\Service;
use App\Notifications\Concerns\CanceladoPelaPiquet;
use App\Notifications\Concerns\RoutesExpoToPushQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/** A equipa da Piquet cancelou o pedido do cliente (ver CancelarPeloBackoffice). */
class PedidoCanceladoPelaPiquetNotification extends Notification implements ShouldQueue
{
    use CanceladoPelaPiquet;
    use Queueable;
    use RoutesExpoToPushQueue;

    public function __construct(private readonly Service $service) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        [$title, $body] = $this->textosDoCancelamento($this->service, $notifiable, 'cliente');

        return ExpoMessage::create($title)
            ->body($body)
            ->priority('high')
            ->playSound()
            ->data([
                'open_type' => 'service',
                'open_id' => $this->service->id,
            ]);
    }

    public function toArray($notifiable): array
    {
        [$title, $body] = $this->textosDoCancelamento($this->service, $notifiable, 'cliente');

        return [
            'title' => $title,
            'body' => $body,
            'service_id' => $this->service->id,
            'open_type' => 'service',
            'open_id' => $this->service->id,
        ];
    }
}
