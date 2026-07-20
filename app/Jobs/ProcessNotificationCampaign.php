<?php

namespace App\Jobs;

use App\Models\NotificationCampaign;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessNotificationCampaign implements ShouldQueue
{
    use Queueable;

    // Uma só tentativa: se o job demorar mais do que o retry_after da fila e for
    // reclamado, NÃO deve voltar a correr e reenviar as pushes (duplicados vistos em
    // produção: "attempted too many times"). O Cache::lock abaixo protege em paralelo.
    public int $tries = 1;

    // Este job é apenas o DISPATCHER (despacha chunks); deve ser rápido. O envio real vive em
    // SendCampaignNotifications, um job por chunk. O timeout limita um dispatcher preso.
    public int $timeout = 120;

    public function __construct(
        private readonly NotificationCampaign $campaign
    ) {}

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessNotificationCampaign failed', [
            'campaign_id' => $this->campaign->id,
            'error' => $e->getMessage(),
        ]);
    }

    public function handle(): void
    {
        $lock = Cache::lock("campaign:{$this->campaign->id}:processing", 300);

        if (! $lock->get()) {
            return;
        }

        try {
            $this->campaign->refresh();

            if (! $this->campaign->shouldSend()) {
                return;
            }

            $this->process();
        } finally {
            $lock->release();
        }
    }

    private function process(): void
    {
        // Distribuir o envio por chunks: cada SendCampaignNotifications trata ~200 utilizadores,
        // pelo que nenhum job individual excede o retry_after da fila. A deduplicação (recentLog /
        // 'once') e a criação de log por utilizador vivem no job de chunk.
        $this->getTargetUsers()
            ->pluck('id')
            ->chunk(200)
            ->each(fn ($ids) => SendCampaignNotifications::dispatch($this->campaign, $ids->all()));

        $this->campaign->last_sent_at = now();
        $this->campaign->next_send_at = $this->campaign->calculateNextSend();

        if ($this->campaign->frequency_type === 'once') {
            $this->campaign->is_active = false;
        }

        $this->campaign->save();
    }

    private function getTargetUsers(): Collection
    {
        $query = User::query();

        // Filter by target type
        if ($this->campaign->target_type === 'vendor') {
            $query->whereHas('vendor');
        } elseif ($this->campaign->target_type === 'customer') {
            $query->whereDoesntHave('vendor');
        }
        // 'both' doesn't need filtering
        if ($this->campaign->target_type === 'vendor') {
            $query->whereHas('vendor', function ($q) {
                if ($this->campaign->user_status === 'online') {
                    $q->where('status', 'Online');
                } elseif ($this->campaign->user_status === 'offline') {
                    $q->where('status', 'Offline');
                }
                // 'both' doesn't need filtering
            });
        }
        // Only users with devices (push tokens)
        $query->whereHas('devices');

        // Exclude opted-out users (global or per-campaign)
        $query->whereDoesntHave('notificationOptOuts', function ($q) {
            $q->where(function ($inner) {
                $inner->whereNull('notification_campaign_id')
                    ->orWhere('notification_campaign_id', $this->campaign->id);
            });
        });

        return $query->get();
    }
}
