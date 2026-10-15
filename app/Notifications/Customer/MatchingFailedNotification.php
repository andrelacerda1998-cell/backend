<?php

namespace App\Notifications\Customer;

use App\Models\Service;
use App\Notifications\Concerns\RoutesExpoToPushQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * O pedido em seleção terminou sem profissional — ver docs/matching.md.
 *
 * O desfecho negativo tem de chegar pelo mesmo caminho que o positivo. Sem
 * isto, quem fechou a app ficava a acreditar que o pedido continuava vivo e
 * só descobria que não ao voltar — possivelmente horas depois, com o problema
 * por resolver e sem ter tentado outra coisa entretanto.
 *
 * Sem som e sem prioridade alta, ao contrário do aviso de que há
 * profissionais: é informação de desfecho, não um pedido de ação urgente.
 */
class MatchingFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use RoutesExpoToPushQueue;

    /** Ninguém aceitou: o texto de sempre. */
    public const SEM_PROFISSIONAIS = 'sem_profissionais';

    /** Houve quem aceitasse e o cliente não escolheu a tempo. */
    public const ESCOLHA_EXPIROU = 'escolha_expirou';

    /** Escolheu e não pagou a tempo. */
    public const PAGAMENTO_EXPIROU = 'pagamento_expirou';

    /**
     * O motivo muda o texto. Até 15/10/2026 havia um só, "Ninguém disponível
     * de momento", e ia também a quem tinha profissionais à espera e ficou
     * sem tempo para escolher — e quem não pagava a tempo não recebia nada.
     */
    public function __construct(
        private readonly Service $service,
        private readonly string $motivo = self::SEM_PROFISSIONAIS,
    ) {}

    public function via($notifiable): array
    {
        // O histórico primeiro: com a fila síncrona, um push que falhe já não
        // deixava escrever o registo que vinha a seguir.
        return ['database', 'expo'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        return ExpoMessage::create($this->title($notifiable))
            ->body($this->body($notifiable));
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => $this->title($notifiable),
            'body' => $this->body($notifiable),
        ];
    }

    private function language($notifiable): string
    {
        return $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
    }

    private function title($notifiable): string
    {
        if ($this->motivo !== self::SEM_PROFISSIONAIS) {
            return __("notifications.matchingExpired.{$this->motivo}.title", [], $this->language($notifiable));
        }

        return __('notifications.matchingFailed.title', [], $this->language($notifiable));
    }

    private function body($notifiable): string
    {
        $language = $this->language($notifiable);

        if ($this->motivo !== self::SEM_PROFISSIONAIS) {
            return __("notifications.matchingExpired.{$this->motivo}.description", [], $language);
        }

        return __('notifications.matchingFailed.description', [
            'service_type' => $this->service->serviceType?->getTranslation('name', $language) ?? '',
        ], $language);
    }
}
