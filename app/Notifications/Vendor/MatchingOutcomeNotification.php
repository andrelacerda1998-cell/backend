<?php

namespace App\Notifications\Vendor;

use App\Models\ServiceCandidate;
use App\Notifications\Concerns\RoutesExpoToPushQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

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
 * EXCEÇÃO (`$comPush`), pedida pelo André a 15/10/2026: o ESCOLHIDO cujo
 * cliente não pagou. Não é dizer-lhe que perdeu — é dizer-lhe que já não está
 * à espera de nada. Foi avisado de que foi escolhido, e num imediato ficou
 * fora de outros convites enquanto o cliente pagava. Sem push, só o sabia ao
 * abrir a app.
 *
 * NAO passa pela preferencia "novos pedidos" (`RespectsVendorPreference`), ao
 * contrario do convite. Quem desliga os avisos de pedidos novos nao esta a dizer
 * que nao quer saber o que aconteceu a um pedido que aceitou — e sem push nao ha
 * incomodo nenhum para poupar.
 */
class MatchingOutcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RoutesExpoToPushQueue;

    /** @param 'lost'|'closed' $outcome */
    public function __construct(
        private readonly ServiceCandidate $candidate,
        private readonly string $outcome,
        private readonly bool $comPush = false,
    ) {}

    public function via($notifiable): array
    {
        return $this->comPush ? ['expo', 'database'] : ['database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $this->language($notifiable);

        return ExpoMessage::create(__("notifications.matchingOutcome.{$this->outcome}.title", [], $language))
            ->body(__("notifications.matchingOutcome.{$this->outcome}.description", [], $language))
            ->priority('high')
            ->playSound()
            ->channelId('requests');
    }

    private function language($notifiable): string
    {
        return $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray($notifiable): array
    {
        $language = $this->language($notifiable);

        return [
            'title' => __("notifications.matchingOutcome.{$this->outcome}.title", [], $language),
            'body' => __("notifications.matchingOutcome.{$this->outcome}.description", [], $language),
            'service_id' => $this->candidate->service_id,
            'candidate_id' => $this->candidate->id,
            'outcome' => $this->outcome,
        ];
    }
}
