<?php

namespace App\Notifications;

use App\Models\NotificationCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Expo\ExpoMessage;

class CampaignNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly NotificationCampaign $campaign,
        private readonly ?int $campaignLogId = null,
    ) {}

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

        $title = is_array($this->campaign->title)
            ? ($this->campaign->title[$language] ?? $this->campaign->title['en'] ?? $this->campaign->name)
            : $this->campaign->title;

        $body = is_array($this->campaign->body)
            ? ($this->campaign->body[$language] ?? $this->campaign->body['en'] ?? '')
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

        $title = is_array($this->campaign->title)
            ? ($this->campaign->title[$language] ?? $this->campaign->title['en'] ?? $this->campaign->name)
            : $this->campaign->title;

        $body = is_array($this->campaign->body)
            ? ($this->campaign->body[$language] ?? $this->campaign->body['en'] ?? '')
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
