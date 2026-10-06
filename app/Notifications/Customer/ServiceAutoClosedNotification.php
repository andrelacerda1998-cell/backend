<?php

namespace App\Notifications\Customer;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * O serviço fechou sozinho: passaram as horas sem confirmação nem problema.
 *
 * O cliente não pode descobrir pelo extrato do banco que foi cobrado. E é o
 * momento de pedir a avaliação, que de outra forma se perdia — quem não
 * confirmou também não avaliou. Abre o detalhe do serviço, onde se avalia.
 */
class ServiceAutoClosedNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable;

    public function __construct(private readonly Service $service) {}

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        [$title, $body] = $this->textos($notifiable);

        return ExpoMessage::create($title)
            ->body($body)
            ->data(['open_type' => 'history', 'open_id' => $this->service->id]);
    }

    public function toArray($notifiable): array
    {
        [$title, $body] = $this->textos($notifiable);

        return [
            'title' => $title,
            'body' => $body,
            'open_type' => 'history',
            'open_id' => $this->service->id,
        ];
    }

    /** @return array{0: string, 1: string} */
    private function textos($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return [
            __('notifications.serviceAutoClosed.title', [], $language),
            __('notifications.serviceAutoClosed.description', [
                'service_type' => $this->service->titulo($language) ?? '',
                'hours' => Service::HORAS_ATE_FECHO_AUTOMATICO,
            ], $language),
        ];
    }
}
