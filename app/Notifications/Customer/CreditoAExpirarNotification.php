<?php

namespace App\Notifications\Customer;

use App\Services\Carteira\Convites;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * "O teu crédito de 5 € expira a 07/12." Uma semana antes, uma vez por crédito
 * (ver AvisarCreditoAExpirarCommand). Abre a Carteira.
 */
class CreditoAExpirarNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable;

    /**
     * @param  int  $valor  Em cêntimos: o que sobra dos créditos que expiram.
     * @param  bool  $soNoPrimeiroServico  O crédito de quem foi convidado só se usa
     *                                     no primeiro serviço, e de 30 € ou mais.
     */
    public function __construct(
        public readonly int $valor,
        public readonly CarbonInterface $expiraEm,
        public readonly bool $soNoPrimeiroServico = false,
    ) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        [$title, $body] = $this->textos($notifiable);

        return ExpoMessage::create($title)->body($body)->data(['open_type' => 'wallet']);
    }

    public function toArray($notifiable): array
    {
        [$title, $body] = $this->textos($notifiable);

        return ['title' => $title, 'body' => $body, 'open_type' => 'wallet'];
    }

    /** @return array{0: string, 1: string} */
    private function textos($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $valor = rtrim(rtrim(number_format($this->valor / 100, 2, ',', ' '), '0'), ',');

        return [
            __('notifications.creditoAExpirar.title', ['valor' => $valor, 'data' => $this->expiraEm->format('d/m')], $language),
            $this->soNoPrimeiroServico
                ? __('notifications.creditoAExpirar.body_primeiro_servico', ['minimo' => number_format(Convites::MINIMO_DO_SERVICO / 100, 0)], $language)
                : __('notifications.creditoAExpirar.body', [], $language),
        ];
    }
}
