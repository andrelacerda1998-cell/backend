<?php

namespace App\Notifications\Vendor;

use App\Notifications\Concerns\RespectsVendorPreference;
use App\Notifications\Concerns\RoutesExpoToPushQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/** O prazo acabou e o saldo foi transferido para a plataforma. */
class AtDeadlineForfeitedNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsVendorPreference;
    use RoutesExpoToPushQueue;

    public function __construct(private int $centimos) {}

    /**
     * NÃO respeita a preferência de notificações, ao contrário das outras.
     *
     * Um técnico que desligou os avisos de pagamentos continua a ter direito a
     * saber que perdeu dinheiro. Silenciar isto seria deixá-lo descobrir pelo
     * saldo, sem explicação — e é a única notificação desta app que comunica
     * uma perda.
     */
    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    private function parametros(): array
    {
        return ['value' => number_format($this->centimos / 100, 2, ',', ' ')];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $lang = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return ExpoMessage::create(__('notifications.atDeadline.forfeited.title', $this->parametros(), $lang))
            ->body(__('notifications.atDeadline.forfeited.description', $this->parametros(), $lang))
            ->priority('high')
            ->playSound();
    }

    public function toArray($notifiable): array
    {
        $lang = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return [
            'title' => __('notifications.atDeadline.forfeited.title', $this->parametros(), $lang),
            'body' => __('notifications.atDeadline.forfeited.description', $this->parametros(), $lang),
        ];
    }
}
