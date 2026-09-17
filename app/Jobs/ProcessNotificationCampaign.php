<?php

namespace App\Jobs;

use App\Enums\Services\AddressType;
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
        // Só quem tem dispositivo registado: sem token não há push nenhum, e
        // contá-los inflacionava o alcance da campanha com gente que nunca
        // poderia receber.
        $query->whereHas('devices');

        $this->applyStateFilters($query);

        // Exclude opted-out users (global or per-campaign)
        $query->whereDoesntHave('notificationOptOuts', function ($q) {
            $q->where(function ($inner) {
                $inner->whereNull('notification_campaign_id')
                    ->orWhere('notification_campaign_id', $this->campaign->id);
            });
        });

        $users = $query->get();

        // A elegibilidade do tecnico e um atributo CALCULADO (`can_accept_service`
        // junta documentos, IBAN, AT, workspace, contactos verificados), por isso
        // nao da para filtrar em SQL. Filtra-se depois — o que e coerente com
        // este metodo, que ja carrega tudo antes de dividir em blocos.
        if (in_array($this->campaign->target_type, ['vendor', 'both'], true)
            && filled($this->campaign->vendor_eligibility)) {
            $pronto = $this->campaign->vendor_eligibility === 'ready';

            $users = $users->filter(function (User $user) use ($pronto) {
                // Um cliente nunca e filtrado por um criterio de tecnico: numa
                // campanha "both" ele nao tem elegibilidade nenhuma a avaliar.
                if (! $user->vendor) {
                    return true;
                }

                return (bool) $user->vendor->can_accept_service === $pronto;
            })->values();
        }

        return $users;
    }

    /**
     * Filtros de estado que dao para fazer em SQL.
     *
     * Existem para as campanhas que valem a pena: falar com quem esta encravado
     * nalgum sitio concreto, em vez de com toda a gente.
     */
    private function applyStateFilters($query): void
    {
        // Tecnicos sem morada de agendamento. Enquanto nao a tiverem, a
        // distancia — e logo o preco — dos servicos agendados sai da morada
        // fiscal, que pode ser o escritorio do contabilista.
        if ($this->campaign->vendor_missing_schedule_address) {
            $query->whereDoesntHave('addresses', function ($q) {
                $q->where('address_type', AddressType::SCHEDULE_ADDRESS);
            });
        }

        // Clientes que se registaram e nunca pediram nada.
        if ($this->campaign->customer_never_requested) {
            $query->whereDoesntHave('services');
        }

        // Sem servicos ha N dias. Vale para os dois lados: um cliente conta
        // pelos servicos que pediu, um tecnico pelos que executou — por isso
        // nao chega olhar para uma relacao so.
        if ($dias = $this->campaign->inactive_days) {
            $desde = now()->subDays($dias);

            $query->whereDoesntHave('services', fn ($q) => $q->where('services.created_at', '>=', $desde))
                ->whereDoesntHave('vendor.services', fn ($q) => $q->where('services.created_at', '>=', $desde));
        }
    }
}
