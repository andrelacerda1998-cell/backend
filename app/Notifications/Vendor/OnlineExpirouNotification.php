<?php

namespace App\Notifications\Vendor;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * "Passaste a offline." Ver ExpirarOnlineCommand.
 *
 * Sem ela o técnico descobria só pelos pedidos que deixavam de chegar — e
 * podia nunca descobrir, que é o contrário do que se quer.
 */
class OnlineExpirouNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable;

    public function __construct(public int $horas) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        return ExpoMessage::create($this->titulo($notifiable))
            ->body($this->texto($notifiable))
            ->playSound();
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'online_expirou',
            'title' => $this->titulo($notifiable),
            'body' => $this->texto($notifiable),
        ];
    }

    private function idioma($notifiable): string
    {
        return $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
    }

    private function titulo($notifiable): string
    {
        return __('notifications.onlineExpirou.title', [], $this->idioma($notifiable));
    }

    private function texto($notifiable): string
    {
        return __('notifications.onlineExpirou.description', ['dias' => intdiv($this->horas, 24)], $this->idioma($notifiable));
    }
}
