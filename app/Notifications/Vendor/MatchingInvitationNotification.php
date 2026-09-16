<?php

namespace App\Notifications\Vendor;

use App\Models\ServiceCandidate;
use App\Notifications\Concerns\RespectsVendorPreference;
use App\Notifications\Concerns\RoutesExpoToPushQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * Convite de seleção — ver docs/matching.md.
 *
 * Existe porque o convite só viajava por websocket: com a app em segundo plano,
 * a janela de resposta passava sem o profissional saber que tinha existido. Um
 * convite silencioso é um convite perdido, e perde-o duas vezes — não responde,
 * e aprende que a app não o avisa.
 *
 * A mensagem diz explicitamente que o cliente escolhe. Prometer aqui o que não
 * está garantido é a origem da frustração que a spec quer evitar.
 */
class MatchingInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsVendorPreference;
    use RoutesExpoToPushQueue;

    public function __construct(private readonly ServiceCandidate $candidate) {}

    public function via($notifiable): array
    {
        return $this->applyVendorPreference($notifiable, 'new_requests', ['expo']);
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $serviceType = $this->candidate->service?->serviceType;

        $title = __('notifications.matchingInvitation.title', [], $language)
            .($serviceType?->getTranslation('name', $language) ?? '');

        return ExpoMessage::create($title)
            ->body(__('notifications.matchingInvitation.description', [], $language))
            ->priority('high')
            ->playSound()
            // Canal Android dedicado: um convite silencioso é um convite perdido.
            ->channelId('requests')
            // `matching_invitation` abre o ecra do convite, com a decisao la
            // dentro. Era 'request', que na app abre o ecra da adjudicacao
            // direta — esse procura o pedido na lista de PENDENTES, onde um
            // convite de selecao nunca esta, e fechava-se sozinho. Quem tocava
            // na notificacao via o ecra abrir e fechar, sem nada.
            //
            // O `open_id` passa a ser o CANDIDATO e nao o servico: e o
            // candidato que identifica o convite deste profissional (o mesmo
            // servico tem varios). `candidate_id` fica por compatibilidade com
            // versoes da app ja publicadas.
            ->data([
                'open_type' => 'matching_invitation',
                'open_id' => $this->candidate->id,
                'candidate_id' => $this->candidate->id,
                'service_id' => $this->candidate->service_id,
            ]);
    }
}
