<?php

namespace App\Notifications\Vendor;

use App\Models\ServiceCandidate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * O cliente escolheu-o e falta o pagamento.
 *
 * Entre a escolha e o pagamento o cliente tem minutos (5 num pedido normal,
 * mais no agendado com antecedência e no personalizado), e durante esse tempo
 * o profissional não sabia de nada. Num imediato nem sequer recebe outros
 * convites imediatos (`Vendor::ocupadoAgora`), sem perceber porquê.
 *
 * SEM PUSH, como o desfecho (`MatchingOutcomeNotification`). Um push a dizer
 * "foste escolhido" seguido de um cancelamento sem push seria pior do que
 * nada. A notícia que vale o telemóvel é a do pagamento, e essa já tem push
 * (`ServiceWonNotification`). O aviso ao vivo, para quem tem a app aberta, é o
 * websocket; isto fica no histórico.
 *
 * Classe à parte, e não um terceiro desfecho: o desfecho é o fim do convite, e
 * este ainda não é. Os testes do desfecho garantem que o escolhido não recebe
 * nenhum.
 */
class MatchingSelectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ServiceCandidate $candidate,
        private readonly ?string $payBy,
    ) {}

    public function via($notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return [
            'title' => __('notifications.matchingSelected.title', [], $language),
            'body' => __('notifications.matchingSelected.description', [], $language),
            'service_id' => $this->candidate->service_id,
            'candidate_id' => $this->candidate->id,
            'pay_by' => $this->payBy,
        ];
    }
}
