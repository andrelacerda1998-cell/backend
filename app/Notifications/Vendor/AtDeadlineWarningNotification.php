<?php

namespace App\Notifications\Vendor;

use App\Notifications\Concerns\RespectsVendorPreference;
use App\Notifications\Concerns\RoutesExpoToPushQueue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * Faltam N dias para dar a AT, ou o dinheiro perde-se.
 *
 * Vai por push E por base de dados, ao contrário do aviso de desfecho do
 * matching: aqui há uma consequência com data marcada, e um aviso que só
 * aparece a quem abrir a app é um aviso que não chega a quem parou de a abrir
 * — que é precisamente quem está em risco de perder o dinheiro.
 */
class AtDeadlineWarningNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsVendorPreference;
    use RoutesExpoToPushQueue;

    public function __construct(private int $dias, private int $centimos) {}

    public function via($notifiable): array
    {
        return $this->applyVendorPreference($notifiable, 'payments', ['expo', 'database']);
    }

    private function chave(): string
    {
        return $this->dias === 0 ? 'lastDay' : 'daysLeft';
    }

    private function parametros(): array
    {
        return ['count' => $this->dias, 'value' => number_format($this->centimos / 100, 2, ',', ' ')];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $lang = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return ExpoMessage::create(__('notifications.atDeadline.'.$this->chave().'.title', $this->parametros(), $lang))
            ->body(__('notifications.atDeadline.'.$this->chave().'.description', $this->parametros(), $lang))
            ->priority('high')
            ->playSound();
    }

    public function toArray($notifiable): array
    {
        $lang = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        return [
            'title' => __('notifications.atDeadline.'.$this->chave().'.title', $this->parametros(), $lang),
            'body' => __('notifications.atDeadline.'.$this->chave().'.description', $this->parametros(), $lang),
        ];
    }
}
