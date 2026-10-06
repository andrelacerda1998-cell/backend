<?php

namespace App\Notifications\Vendor;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * "Estás online, mas não recebemos a tua localização."
 *
 * Ver AvisarOnlineSemLocalizacaoCommand. Abrir a app volta a ligar o envio.
 */
class OnlineSemLocalizacaoNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable;

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return ExpoMessage::create(__('notifications.onlineSemLocalizacao.title', [], $language))
            ->body(__('notifications.onlineSemLocalizacao.description', [], $language))
            ->playSound();
    }

    public function toArray($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return [
            'type' => 'online_sem_localizacao',
            'title' => __('notifications.onlineSemLocalizacao.title', [], $language),
            'body' => __('notifications.onlineSemLocalizacao.description', [], $language),
        ];
    }
}
