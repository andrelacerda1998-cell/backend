<?php

namespace App\Notifications\Customer;

use App\Models\Referral\Referral;
use App\Services\Carteira\Convites;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\Expo\ExpoMessage;

/**
 * Um amigo convidado fez o primeiro serviço: quem o convidou ganhou 5 € na
 * Carteira. Abre a Carteira.
 *
 * Também por email (desde 14/10/2026): o push perde-se, e é a recompensa que
 * faz alguém voltar a convidar.
 */
class ConviteRecompensaNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable;

    public function __construct(private readonly Referral $convite) {}

    public function via($notifiable): array
    {
        $canais = ['expo', 'database'];

        // Sem email, ou com o email de uma conta anonimizada, não há a quem escrever.
        $email = (string) ($notifiable->email ?? '');
        if ($email !== '' && ! Str::endsWith($email, '@anonymized.invalid')) {
            $canais[] = 'mail';
        }

        return $canais;
    }

    public function toMail($notifiable): MailMessage
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');
        $valor = number_format(Convites::VALOR / 100, 0);
        $nome = trim((string) ($notifiable->first_name ?? ''));

        return (new MailMessage)
            ->subject(__('notifications.conviteRecompensaEmail.subject', ['valor' => $valor], $language))
            ->greeting($nome !== ''
                ? __('notifications.conviteRecompensaEmail.greeting', ['nome' => $nome], $language)
                : __('notifications.conviteRecompensaEmail.greeting_sem_nome', [], $language))
            ->line(__('notifications.conviteRecompensaEmail.line1', ['amigo' => $this->amigo($language)], $language))
            ->line(__('notifications.conviteRecompensaEmail.line2', ['valor' => $valor, 'meses' => Convites::MESES_DE_QUEM_CONVIDA], $language))
            ->line(__('notifications.conviteRecompensaEmail.line3', [], $language))
            ->salutation(__('notifications.conviteRecompensaEmail.salutation', [], $language));
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
        $amigo = $this->amigo($language);

        return [
            __('notifications.conviteRecompensa.title', ['valor' => number_format(Convites::VALOR / 100, 0)], $language),
            __('notifications.conviteRecompensa.description', [
                'amigo' => $amigo,
                'valor' => number_format(Convites::VALOR / 100, 0),
                'meses' => Convites::MESES_DE_QUEM_CONVIDA,
            ], $language),
        ];
    }

    /** Só o primeiro nome do amigo: é o suficiente para se saber quem foi (ver a Política de Privacidade 1.2). */
    private function amigo(string $language): string
    {
        return trim((string) $this->convite->referred?->first_name) ?: __('convites.um_amigo', [], $language);
    }
}
