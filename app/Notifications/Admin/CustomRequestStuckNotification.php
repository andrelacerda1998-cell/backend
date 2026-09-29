<?php

namespace App\Notifications\Admin;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Um pedido personalizado está em análise há demasiado tempo.
 *
 * Só sai de PendingReview quando alguém no backoffice lhe define a duração e
 * as áreas. Até existir este aviso, um pedido esquecido não produzia sinal
 * nenhum: nem o cliente sabia, nem nós — ele ficava a olhar para "Pedido em
 * análise" sem fim à vista.
 *
 * Vai por email (alguém tem de ver hoje) e database (fica no backoffice).
 * Dispara UMA vez por pedido: um aviso repetido todas as horas ensina-se a
 * ignorar, que é o oposto do que se quer.
 */
class CustomRequestStuckNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly Service $service,
        private readonly int $diasUteis,
        private readonly int $prazoDiasUteis,
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $restam = max(0, $this->prazoDiasUteis - $this->diasUteis);

        return (new MailMessage)
            ->subject("Pedido personalizado #{$this->service->id} parado há {$this->diasUteis} dia(s) útil(eis)")
            ->line('Está em análise e nenhum profissional sabe que existe.')
            ->line('O que o cliente escreveu: '.$this->resumo())
            ->line($restam > 0
                ? "Faltam {$restam} dia(s) útil(eis) para o pedido falhar sozinho e o cliente ser avisado."
                : 'O prazo esgotou-se: o pedido vai falhar na próxima passagem.')
            ->action('Abrir o pedido', url("/backoffice/services/{$this->service->id}"))
            ->line('Definir a duração e as áreas despacha-o e faz sair a primeira onda de convites.');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'custom_request_stuck',
            'service_id' => $this->service->id,
            'weekdays_waiting' => $this->diasUteis,
            'deadline_weekdays' => $this->prazoDiasUteis,
            'description' => $this->resumo(),
        ];
    }

    /** O suficiente para se reconhecer o pedido num email, sem o despejar todo. */
    private function resumo(): string
    {
        $texto = trim((string) $this->service->custom_description);

        return mb_strlen($texto) > 160 ? mb_substr($texto, 0, 160).'…' : $texto;
    }
}
