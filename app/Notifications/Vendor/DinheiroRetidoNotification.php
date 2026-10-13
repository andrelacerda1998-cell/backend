<?php

namespace App\Notifications\Vendor;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * "Tens X € à tua espera. Para os receberes, falta o teu IBAN."
 *
 * Ver LembrarDinheiroRetidoCommand. Só sai quando o que falta depende do
 * técnico (IBAN, morada fiscal, AT); a fatura por emitir é da Piquet.
 */
class DinheiroRetidoNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable;

    /**
     * @param  string  $razao  O código do Vendor::payoutHoldReason():
     *                         iban_missing | fiscal_address_missing | at_user_missing
     */
    public function __construct(public string $valor, public string $razao) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        [$titulo, $texto] = $this->textos($notifiable);

        return ExpoMessage::create($titulo)->body($texto)->playSound();
    }

    public function toArray($notifiable): array
    {
        [$titulo, $texto] = $this->textos($notifiable);

        return [
            'type' => 'dinheiro_retido',
            'reason' => $this->razao,
            'title' => $titulo,
            'body' => $texto,
        ];
    }

    /** @return array{0: string, 1: string} */
    private function textos($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return [
            __('notifications.dinheiroRetido.title', ['valor' => $this->valor], $language),
            __('notifications.dinheiroRetido.'.$this->razao, [], $language),
        ];
    }
}
