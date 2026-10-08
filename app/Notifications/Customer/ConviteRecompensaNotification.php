<?php

namespace App\Notifications\Customer;

use App\Models\Referral\Referral;
use App\Services\Carteira\Convites;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * Um amigo convidado fez o primeiro serviço: quem o convidou ganhou 5 € na
 * Carteira. Abre a Carteira.
 */
class ConviteRecompensaNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable;

    public function __construct(private readonly Referral $convite) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        [$title, $body] = $this->textos($notifiable);

        return ExpoMessage::create($title)
            ->body($body)
            ->data(['open_type' => 'wallet', 'open_id' => $this->convite->id]);
    }

    public function toArray($notifiable): array
    {
        [$title, $body] = $this->textos($notifiable);

        return ['title' => $title, 'body' => $body, 'open_type' => 'wallet', 'open_id' => $this->convite->id];
    }

    /** @return array{0: string, 1: string} */
    private function textos($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        // Só o primeiro nome do amigo: é o suficiente para se saber quem foi.
        $amigo = trim((string) $this->convite->referred?->first_name) ?: __('convites.um_amigo', [], $language);

        return [
            __('notifications.conviteRecompensa.title', ['valor' => number_format(Convites::VALOR / 100, 0)], $language),
            __('notifications.conviteRecompensa.description', [
                'amigo' => $amigo,
                'valor' => number_format(Convites::VALOR / 100, 0),
                'meses' => Convites::MESES_DE_QUEM_CONVIDA,
            ], $language),
        ];
    }
}
