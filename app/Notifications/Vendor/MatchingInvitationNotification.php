<?php

namespace App\Notifications\Vendor;

use App\Models\ServiceCandidate;
use App\Notifications\Concerns\RespectsVendorPreference;
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
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable, RespectsVendorPreference;

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
            // 'request' abre a lista de convites na app do profissional.
            ->data([
                'open_type' => 'request',
                'open_id' => $this->candidate->service_id,
                'candidate_id' => $this->candidate->id,
            ]);
    }
}
