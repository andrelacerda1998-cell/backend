<?php

namespace App\Notifications\Customer;

use App\Models\Service;
use App\Notifications\Concerns\RoutesExpoToPushQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * O profissional não respondeu dentro do prazo e o pedido foi cancelado —
 * ver CancelJobWithoutReactionJob.
 *
 * O desfecho negativo tem de chegar pelo mesmo caminho que o positivo. Até
 * aqui este cancelamento só emitia dois eventos de broadcast: quem tinha a app
 * aberta via o cronómetro chegar ao fim, quem a fechou não sabia de nada. E o
 * prazo do agendado é de 20 minutos — ninguém fica 20 minutos a olhar para um
 * ecrã. O cliente ficava a acreditar que o pedido continuava vivo.
 *
 * Sem som e sem prioridade alta, como o MatchingFailedNotification: é
 * informação de desfecho, não um pedido de ação urgente.
 *
 * Não se promete aqui a devolução do dinheiro. O ServiceObserver trata dela ao
 * cancelar, mas em best-effort — conforme o estado da ordem no Payshop pode ser
 * reembolso, libertação de cativo, ou nada a fazer, e uma falha é reportada sem
 * bloquear o cancelamento. Escrever "o valor foi devolvido" numa push seria
 * prometer o que ainda não se sabe.
 */
class ServiceTimedOutNotification extends Notification implements ShouldQueue
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
        return __('notifications.serviceTimedOut.title', [], $this->language($notifiable));
    }

    /**
     * Agendado e imediato são experiências diferentes — num esperou-se 20
     * minutos por um profissional escolhido, no outro 3 minutos por quem
     * aparecesse. A palavra "agendamento" evita que o cliente ache que lhe
     * caiu outra coisa qualquer.
     */
    private function body($notifiable): string
    {
        $language = $this->language($notifiable);
        $service = $this->service->loadMissing('serviceType');

        // withTrashed: o mesmo cancelamento que dispara este aviso liberta a
        // agenda do técnico apagando a marcação (soft delete, ver
        // CancelJobWithoutReactionJob). Sem isto, um agendamento chegava aqui
        // como se tivesse sido um pedido imediato — a marcação já não existe.
        // A pergunta é histórica: isto foi marcado para uma hora, ou não?
        $eraAgendamento = $service->schedule()->withTrashed()->exists();

        $chave = $eraAgendamento
            ? 'notifications.serviceTimedOut.description_scheduled'
            : 'notifications.serviceTimedOut.description';

        return __($chave, [
            'service_type' => $service->serviceType?->getTranslation('name', $language) ?? '',
        ], $language);
    }
}
