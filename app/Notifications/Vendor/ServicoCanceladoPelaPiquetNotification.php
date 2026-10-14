<?php

namespace App\Notifications\Vendor;

use App\Models\Service;
use App\Notifications\Concerns\CanceladoPelaPiquet;
use App\Notifications\Concerns\RoutesExpoToPushQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * A equipa da Piquet cancelou um serviço que era deste técnico (ver
 * CancelarPeloBackoffice). Operacionalmente crítico, como o aviso de
 * cancelamento pelo cliente: não respeita as preferências de notificação.
 */
class ServicoCanceladoPelaPiquetNotification extends Notification implements ShouldQueue
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
        [$title, $body] = $this->textosDoCancelamento($this->service, $notifiable, 'tecnico');

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
        [$title, $body] = $this->textosDoCancelamento($this->service, $notifiable, 'tecnico');

        return [
            'title' => $title,
            'body' => $body,
            'service_id' => $this->service->id,
            'open_type' => 'service',
            'open_id' => $this->service->id,
        ];
    }
}
