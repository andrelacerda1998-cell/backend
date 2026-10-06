<?php

namespace App\Notifications\Customer;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * O técnico concluiu.
 *
 * Diz ao cliente o que acontece a seguir: tem `Service::HORAS_ATE_FECHO_AUTOMATICO`
 * horas para reportar um problema; depois disso o serviço fecha sozinho. Antes
 * dizia só "o profissional diz ter terminado" — e o cliente não sabia que o
 * pagamento do técnico estava à espera dele.
 *
 * Abre o serviço (open_type 'service'), onde estão o "Confirmar" e o
 * "Reportar um problema".
 */
class ServiceFinishedNotification extends Notification implements ShouldQueue
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

        return [
            'title' => $title,
            'body' => $body,
            'open_type' => 'service',
            'open_id' => $this->service->id,
        ];
    }

    /** @return array{0: string, 1: string} */
    private function textos($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return [
            __('notifications.finishedService.title', [], $language),
            __('notifications.finishedService.description', [
                'service_type' => $this->service->titulo($language) ?? '',
                'hours' => Service::HORAS_ATE_FECHO_AUTOMATICO,
            ], $language),
        ];
    }
}
