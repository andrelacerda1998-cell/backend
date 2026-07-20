<?php

namespace App\Notifications\Customer\Mbway;

use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

class PaymentExpiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    // O canal Expo faz um POST HTTP sem timeout próprio (o cliente Guzzle do pacote é
    // instanciado sem timeout). Sem estes limites, um exp.host lento pendurava o worker até
    // ao teto do processo; como $timeout (15s) < retry_after do queue (90s), o job nunca é
    // re-reservado por um 2º worker — o que eliminava a colisão de UUID em failed_jobs (1062)
    // do mesmo job logado duas vezes. $tries=1: um push de "pagamento expirou" não vale um
    // retry (baixa criticidade), e sem retries não há re-log do mesmo UUID.
    public int $tries = 1;

    public int $timeout = 15;

    public int $backoff = 10;

    public function __construct(private readonly Service $service) {}

    public function via($notifiable): array
    {
        return ['expo'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $language = $this->service->customer->language ?? $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return ExpoMessage::create(__('notifications.mbway.paymentExpired.title', [], $language))
            ->body(__('notifications.mbway.paymentExpired.description', [], $language).$this->service->serviceType->name)
            ->priority('high')
            ->playSound();
    }
}
