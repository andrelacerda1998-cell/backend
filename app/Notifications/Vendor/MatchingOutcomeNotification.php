<?php

namespace App\Notifications\Vendor;

use App\Models\ServiceCandidate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * O desfecho de um convite que o profissional ACEITOU.
 *
 * Dois desfechos possiveis depois do sim dele, e nenhum deles e ele ganhar:
 *
 *   · `lost`   — o cliente escolheu outra pessoa;
 *   · `closed` — o cliente escolheu-o e nao pagou a tempo.
 *
 * Os dois ja viajavam por websocket. O que nao existia era registo: o
 * `notifyVendor` saia antes de escrever notificacao nenhuma, por isso quem
 * tivesse a app fechada no momento do evento nao recebia nada, e ao abri-la
 * encontrava o cartao simplesmente desaparecido da lista. O comentario que
 * justificava a ausencia de push dizia que estas coisas "chegam quando ele abrir
 * a app" — e nada as levava lá.
 *
 * SEM PUSH, e isso mantem-se: tocar-lhe o telemovel para dizer que nao ganhou
 * seria castiga-lo por ter aceitado, e a decisao de nao o fazer e deliberada.
 * Canal `database` apenas — o aviso ao vivo, para quem tem a app aberta,
 * continua a ser o websocket.
 *
 * NAO passa pela preferencia "novos pedidos" (`RespectsVendorPreference`), ao
 * contrario do convite. Quem desliga os avisos de pedidos novos nao esta a dizer
 * que nao quer saber o que aconteceu a um pedido que aceitou — e sem push nao ha
 * incomodo nenhum para poupar.
 */
class MatchingOutcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param 'lost'|'closed' $outcome */
    public function __construct(
        private readonly ServiceCandidate $candidate,
        private readonly string $outcome,
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
            'title' => __("notifications.matchingOutcome.{$this->outcome}.title", [], $language),
            'body' => __("notifications.matchingOutcome.{$this->outcome}.description", [], $language),
            'service_id' => $this->candidate->service_id,
            'candidate_id' => $this->candidate->id,
            'outcome' => $this->outcome,
        ];
    }
}
