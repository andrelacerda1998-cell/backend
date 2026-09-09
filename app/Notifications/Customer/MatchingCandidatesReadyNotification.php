<?php

namespace App\Notifications\Customer;

use App\Models\Service;
use App\Notifications\Concerns\RoutesExpoToPushQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * O primeiro profissional aceitou — ver docs/matching.md.
 *
 * Sem isto o cliente só sabia que alguém tinha aceitado se estivesse com o
 * ecrã de seleção aberto: todas as notificações do matching iam para o
 * profissional, nenhuma vinha para cá. Num pedido imediato sair da app é
 * exatamente o que se faz — a pessoa tem uma canalização a verter e não está
 * a olhar para o telemóvel — e o relógio de escolha corre à mesma. Sem aviso,
 * o pedido morria em silêncio com profissionais disponíveis do outro lado.
 *
 * Vai só à PRIMEIRA aceitação. As seguintes mudam a lista, não a decisão que
 * o cliente tem de tomar, e tocar-lhe o telemóvel três vezes pelo mesmo
 * pedido é o caminho para ele desligar as notificações.
 */
class MatchingCandidatesReadyNotification extends Notification implements ShouldQueue
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
            // 'matching' abre o ecrã de seleção deste pedido na app do cliente.
            ->data([
                'open_type' => 'matching',
                'open_id' => $this->service->id,
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => $this->title($notifiable),
            'body' => $this->body($notifiable),
            'open_type' => 'matching',
            'open_id' => $this->service->id,
        ];
    }

    private function language($notifiable): string
    {
        return $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
    }

    private function title($notifiable): string
    {
        return __('notifications.matchingCandidatesReady.title', [], $this->language($notifiable));
    }

    private function body($notifiable): string
    {
        $language = $this->language($notifiable);

        return __('notifications.matchingCandidatesReady.description', [
            'service_type' => $this->service->serviceType?->getTranslation('name', $language) ?? '',
        ], $language);
    }
}
