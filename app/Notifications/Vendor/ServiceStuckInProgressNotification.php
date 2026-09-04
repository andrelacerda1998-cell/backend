<?php

namespace App\Notifications\Vendor;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * Avisa o técnico de que deixou um serviço "Em execução" e nunca o concluiu.
 *
 * Porque existe: sem "Concluir serviço" o serviço nunca fecha — o técnico NÃO
 * é pago, o cliente não recebe fatura, e nada na app lho diz. Ele vê os Ganhos
 * a zero, conclui que a plataforma não paga, e desaparece sem abrir um ticket.
 * Um esquecimento de um toque custa-lhe o trabalho todo.
 *
 * NÃO respeita notification_preferences, tal como os documentos a expirar:
 * é operacionalmente crítico e mexe diretamente no rendimento.
 */
class ServiceStuckInProgressNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable;

    public function __construct(
        private readonly Service $service,
        private readonly int $hoursInProgress,
    ) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $copy = $this->copy($notifiable);

        return ExpoMessage::create($copy['title'])
            ->body($copy['body'])
            ->priority('high')
            ->playSound()
            ->data([
                'open_type' => 'service',
                'open_id' => $this->service->id,
            ]);
    }

    public function toArray($notifiable): array
    {
        $copy = $this->copy($notifiable);

        return [
            'title' => $copy['title'],
            'body' => $copy['body'],
            'service_id' => $this->service->id,
            'hours_in_progress' => $this->hoursInProgress,
            'open_type' => 'service',
            'open_id' => $this->service->id,
        ];
    }

    /**
     * @return array{title: string, body: string}
     */
    private function copy($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        $serviceName = $this->service->serviceType?->getTranslation('name', $language)
            ?? $this->service->serviceType?->name
            ?? '';

        return [
            'title' => __('notifications.serviceStuck.title', [], $language),
            'body' => __('notifications.serviceStuck.description', [
                'service' => $serviceName,
                'hours' => $this->hoursInProgress,
            ], $language),
        ];
    }
}
