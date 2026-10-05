<?php

namespace App\Notifications\Vendor;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * O cliente reportou um problema num serviço deste técnico.
 *
 * O pagamento fica à espera de uma pessoa da Piquet — e o técnico tem de o
 * saber pela app, não descobrir que o dinheiro não chegou à segunda-feira.
 */
class ServiceProblemReportedNotification extends Notification implements ShouldQueue
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

        return ExpoMessage::create($title)->body($body);
    }

    public function toArray($notifiable): array
    {
        [$title, $body] = $this->textos($notifiable);

        return ['title' => $title, 'body' => $body, 'service_id' => $this->service->id];
    }

    /** @return array{0: string, 1: string} */
    private function textos($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return [
            __('notifications.problemReportedVendor.title', [], $language),
            __('notifications.problemReportedVendor.description', [
                'service_type' => $this->service->serviceType?->getTranslation('name', $language) ?? '',
            ], $language),
        ];
    }
}
