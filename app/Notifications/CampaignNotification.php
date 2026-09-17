<?php

namespace App\Notifications;

use App\Models\NotificationCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

class CampaignNotification extends Notification implements ShouldQueue
{
    use \App\Notifications\Concerns\RoutesExpoToPushQueue;
    use Queueable;

    public function __construct(
        private readonly NotificationCampaign $campaign,
        private readonly ?int $campaignLogId = null,
    ) {}

    /**
     * O log desta campanha para este utilizador.
     *
     * Exposto para o `RecordExpoDeliveryFailure` poder marcar a linha quando a
     * Expo recusa o push — o canal nao lanca excepcao, dispara um evento, e sem
     * isto a recusa nao chegava ao log.
     */
    public function campaignLogId(): ?int
    {
        return $this->campaignLogId;
    }

    public function via($notifiable): array
    {
        return ['expo', 'database'];
    }

    public function toExpo($notifiable): ExpoMessage
    {
        \Log::info('[CampaignNotification] toExpo', [
            'campaignLogId' => $this->campaignLogId,
            'campaignId' => $this->campaign->id,
            'notifiableId' => $notifiable->id,
        ]);

        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        // Cada um recebe no SEU idioma. Sem excepcoes: quem tem a app em
        // portugues recebe portugues, quem a tem em ingles recebe ingles.
        //
        // A traducao por rever nao se resolve aqui — resolve-se nao deixando a
        // campanha sair enquanto for rascunho (ver `NotificationCampaign::
        // shouldSend`). Mandar portugues a quem tem o telemovel em ingles seria
        // esconder o problema no unico sitio onde ele ja nao tem conserto: no
        // telemovel de quem o recebe.
        //
        // Escolhe o primeiro valor preenchido (o Filament pode gravar '' na aba
        // não preenchida, e '??' não pula string vazia): locale -> pt-pt -> en.
        $pick = fn (array $m, $default) => collect([$m[$language] ?? null, $m['pt-pt'] ?? null, $m['en'] ?? null])
            ->first(fn ($v) => filled($v)) ?? $default;

        $title = is_array($this->campaign->title)
            ? $pick($this->campaign->title, $this->campaign->name)
            : $this->campaign->title;

        $body = is_array($this->campaign->body)
            ? $pick($this->campaign->body, '')
            : $this->campaign->body;

        return ExpoMessage::create($title)
            ->body($body)
            ->priority('high')
            ->playSound()
            ->data([
                'campaign_log_id' => $this->campaignLogId,
                ...($notifiable->isCustomer() ? [
                    'open_type' => $this->campaign->open_type,
                    'open_id' => $this->campaign->open_id,
                ] : []),
            ]);
    }

    public function toArray($notifiable): array
    {
        $language = $notifiable->language ?? app()->getLocale() ?? config('app.fallback_locale');

        // Escolhe o primeiro valor preenchido (o Filament pode gravar '' na aba
        // não preenchida, e '??' não pula string vazia): locale -> pt-pt -> en.
        $pick = fn (array $m, $default) => collect([$m[$language] ?? null, $m['pt-pt'] ?? null, $m['en'] ?? null])
            ->first(fn ($v) => filled($v)) ?? $default;

        $title = is_array($this->campaign->title)
            ? $pick($this->campaign->title, $this->campaign->name)
            : $this->campaign->title;

        $body = is_array($this->campaign->body)
            ? $pick($this->campaign->body, '')
            : $this->campaign->body;

        return [
            'title' => $title,
            'body' => $body,
            'campaign_id' => $this->campaign->id,
            'campaign_log_id' => $this->campaignLogId,
            ...($notifiable->isCustomer() ? [
                'open_type' => $this->campaign->open_type,
                'open_id' => $this->campaign->open_id,
            ] : []),
        ];
    }
}
