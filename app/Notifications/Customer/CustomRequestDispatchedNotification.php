<?php

namespace App\Notifications\Customer;

use App\Models\Service;
use App\Notifications\Concerns\RoutesExpoToPushQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * O pedido personalizado saiu da análise e já está com os profissionais.
 *
 * Entre descrever o problema e haver alguém para escolher podem passar horas:
 * o backoffice tem de definir a duração e as áreas antes de sair um convite.
 * Nesse intervalo o cliente só descobria que alguma coisa tinha mexido se
 * abrisse a app — e o push que já existia (`MatchingCandidatesReady`) só sai
 * bem mais tarde, quando o primeiro profissional aceita.
 *
 * Este marca o momento em que uma PESSOA pegou no pedido dele. Não promete
 * profissional nenhum, de propósito: prometer aqui era prometer o que ainda
 * não se sabe.
 */
class CustomRequestDispatchedNotification extends Notification implements ShouldQueue
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
            ->priority('default')
            ->playSound()
            // 'matching' abre o ecrã de acompanhamento deste pedido.
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
        return __('notifications.customRequestDispatched.title', [], $this->language($notifiable));
    }

    private function body($notifiable): string
    {
        return __('notifications.customRequestDispatched.description', [], $this->language($notifiable));
    }
}
