<?php

namespace App\Notifications\Vendor;

use App\Models\Service;
use App\Notifications\Concerns\RoutesExpoToPushQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * O cliente escolheu-o e pagou: o trabalho é dele.
 *
 * Num pedido IMEDIATO, ganhar não produzia sinal nenhum — o `activate()`
 * gravava ACCEPTED e mais nada. Os que perderam eram avisados, em segundos e
 * com motivo; o que ganhou não sabia. Num agendado o mesmo facto manda push e
 * websocket há muito.
 *
 * NÃO respeita a preferência "Novos pedidos", ao contrário do convite. Isto
 * não é um pedido novo a bater à porta: é o desfecho de um a que ele já disse
 * que sim. Silenciá-lo seria deixá-lo sem saber que tem trabalho para fazer.
 *
 * Com som e prioridade alta: alguém está à espera dele agora.
 */
class ServiceWonNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RoutesExpoToPushQueue;

    public function __construct(private readonly Service $service) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        return ExpoMessage::create($this->title($notifiable))
            ->body($this->body($notifiable))
            ->priority('high')
            ->playSound()
            ->channelId('requests')
            ->data([
                'open_type' => 'request',
                'open_id' => $this->service->id,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => $this->title($notifiable),
            'body' => $this->body($notifiable),
            'service_id' => $this->service->id,
        ];
    }

    private function language($notifiable): string
    {
        return $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
    }

    private function title($notifiable): string
    {
        return __('notifications.serviceWon.title', [], $this->language($notifiable));
    }

    private function body($notifiable): string
    {
        $language = $this->language($notifiable);
        $service = $this->service->loadMissing('serviceType');

        $nome = $service->is_custom
            ? $service->custom_description
            : ($service->serviceType?->getTranslation('name', $language) ?? '');

        return __('notifications.serviceWon.description', [
            'service_type' => $nome,
        ], $language);
    }
}
