<?php

namespace App\Jobs;

use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignLog;
use App\Models\User;
use App\Notifications\CampaignNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Envia as notificações de uma campanha a um CHUNK de utilizadores. O ProcessNotificationCampaign
 * despacha um destes por lote (~200 utilizadores) para que nenhum job individual exceda o
 * retry_after da fila — evitando o "attempted too many times" e as pushes duplicadas vistas em
 * produção. Uma só tentativa por chunk: um chunk reclamado NÃO reenvia.
 */
class SendCampaignNotifications implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        private readonly NotificationCampaign $campaign,
        private readonly array $userIds,
    ) {}

    public function handle(): void
    {
        $users = User::query()->whereIn('id', $this->userIds)->get();

        foreach ($users as $user) {
            if ($this->campaign->frequency_type !== 'once') {
                $recentLog = NotificationCampaignLog::where('notification_campaign_id', $this->campaign->id)
                    ->where('user_id', $user->id)
                    ->where('sent_at', '>', now()->subDay())
                    ->where('success', true)
                    ->exists();

                if ($recentLog) {
                    continue;
                }
            }

            $log = NotificationCampaignLog::create([
                'notification_campaign_id' => $this->campaign->id,
                'user_id' => $user->id,
                'sent_at' => now(),
                'success' => false,
            ]);

            try {
                $user->notifyNow(new CampaignNotification($this->campaign, $log->id));
                $log->update(['success' => true]);
            } catch (\Exception $e) {
                Log::error('Failed to send campaign notification', [
                    'campaign_id' => $this->campaign->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
                $log->update(['success' => false, 'error_message' => $e->getMessage()]);
            }
        }
    }
}
