<?php

namespace App\Notifications\Vendor;

use App\Notifications\Concerns\RespectsVendorPreference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

/**
 * Lembra o técnico de que o perfil ficou a meio — e que é isso que o separa
 * de receber pedidos.
 *
 * Porque existe: criar conta e completar o perfil são duas fases distintas
 * (ver o wizard "Completar o teu perfil" na app). Quem sai a meio da segunda
 * só tem o banner na Home a lembrá-lo — ou seja, nada o traz de volta se não
 * abrir a app. Este é o único aviso que vai buscá-lo.
 *
 * Respeita as preferências de notificação ('news'): é motivacional, não
 * operacional — ao contrário de documentos a expirar ou cancelamentos, não
 * está a acontecer nada que o técnico tenha de resolver já.
 */
class IncompleteProfileReminderNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable;
    use RespectsVendorPreference;

    /**
     * @param  int  $missingSteps  Quantos passos faltam no wizard.
     * @param  int  $waitingRequests  Pedidos à espera de resposta na zona do técnico (0 = não mencionar).
     */
    public function __construct(
        private readonly int $missingSteps,
        private readonly int $waitingRequests = 0,
    ) {}

    public function via($notifiable): array
    {
        // O email entra além do push: quem não abre a app também não vê o push.
        return $this->applyVendorPreference($notifiable, 'news', ['expo', 'database', 'mail']);
    }

    public function toExpo($notifiable): ExpoMessage
    {
        $copy = $this->copy($notifiable);

        return ExpoMessage::create($copy['title'])
            ->body($copy['body'])
            ->priority('default')
            ->playSound()
            ->data([
                // A app encaminha isto para o wizard (ver hooks/useNotification.tsx).
                'open_type' => 'complete_profile',
            ]);
    }

    public function toMail($notifiable): MailMessage
    {
        $copy = $this->copy($notifiable);

        return (new MailMessage)
            ->subject($copy['title'])
            ->greeting($copy['greeting'])
            ->line($copy['body'])
            ->action($copy['action'], config('app.vendor_app_url', config('app.url')));
    }

    public function toArray($notifiable): array
    {
        $copy = $this->copy($notifiable);

        return [
            'title' => $copy['title'],
            'body' => $copy['body'],
            'missing_steps' => $this->missingSteps,
            'waiting_requests' => $this->waitingRequests,
            'open_type' => 'complete_profile',
        ];
    }

    /**
     * @return array{title: string, body: string, greeting: string, action: string}
     */
    private function copy($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        // Com pedidos à espera na zona, a mensagem passa a ser sobre o que ele
        // está a perder AGORA — muito mais forte do que "tens tarefas por fazer".
        $key = $this->waitingRequests > 0
            ? 'notifications.incompleteProfile.with_requests'
            : 'notifications.incompleteProfile.default';

        $replace = [
            'steps' => $this->missingSteps,
            'requests' => $this->waitingRequests,
            'name' => $notifiable->first_name ?? $notifiable->name ?? '',
        ];

        return [
            'title' => __($key.'.title', $replace, $language),
            'body' => __($key.'.description', $replace, $language),
            'greeting' => __('notifications.incompleteProfile.greeting', $replace, $language),
            'action' => __('notifications.incompleteProfile.action', $replace, $language),
        ];
    }
}
