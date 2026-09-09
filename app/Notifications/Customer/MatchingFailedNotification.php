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

    public function __construct(private readonly Service $service) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
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
        return __('notifications.matchingFailed.title', [], $this->language($notifiable));
    }

    private function body($notifiable): string
    {
        $language = $this->language($notifiable);

        return __('notifications.matchingFailed.description', [
            'service_type' => $this->service->serviceType?->getTranslation('name', $language) ?? '',
        ], $language);
    }
}
