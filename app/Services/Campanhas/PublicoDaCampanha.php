<?php

namespace App\Services\Campanhas;

use App\Enums\Services\AddressType;
use App\Models\NotificationCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * A quem chega uma campanha de push.
 *
 * Estava dentro do ProcessNotificationCampaign, que a usava para enviar. O
 * backoffice passou a criar campanhas (15/10/2026) e mostra "vai chegar a N
 * pessoas" antes de as ativar: esse número tem de sair da MESMA regra do
 * envio, senão diz uma coisa e manda outra. Por isso as duas coisas leem daqui.
 */
final class PublicoDaCampanha
{
    /** Os utilizadores a notificar, como o envio os escolhe. */
    public function utilizadores(NotificationCampaign $campanha): Collection
    {
        $users = $this->consulta($campanha)->get();

        // A elegibilidade do tecnico e um atributo CALCULADO (`can_accept_service`
        // junta documentos, IBAN, AT, workspace, contactos verificados), por isso
        // nao da para filtrar em SQL. Filtra-se depois.
        if (! $this->filtraElegibilidade($campanha)) {
            return $users;
        }

        $pronto = $campanha->vendor_eligibility === 'ready';

        return $users->filter(function (User $user) use ($pronto) {
            // Um cliente nunca e filtrado por um criterio de tecnico: numa
            // campanha "both" ele nao tem elegibilidade nenhuma a avaliar.
            if (! $user->vendor) {
                return true;
            }

            return (bool) $user->vendor->can_accept_service === $pronto;
        })->values();
    }

    /**
     * Quantos são, com a mesma regra, sem carregar toda a gente de uma vez: o
     * pedido do backoffice corre com a memória curta do servidor web. Os
     * técnicos vão em blocos, porque a elegibilidade só se sabe técnico a
     * técnico.
     */
    public function contar(NotificationCampaign $campanha): int
    {
        if (! $this->filtraElegibilidade($campanha)) {
            return $this->consulta($campanha)->count();
        }

        $pronto = $campanha->vendor_eligibility === 'ready';
        $clientes = $this->consulta($campanha)->whereDoesntHave('vendor')->count();
        $tecnicos = 0;

        $this->consulta($campanha)
            ->whereHas('vendor')
            ->with('vendor')
            ->chunkById(200, function (Collection $users) use (&$tecnicos, $pronto) {
                foreach ($users as $user) {
                    if ((bool) $user->vendor->can_accept_service === $pronto) {
                        $tecnicos++;
                    }
                }
            });

        return $clientes + $tecnicos;
    }

    /** A parte da regra que se faz em SQL. */
    public function consulta(NotificationCampaign $campanha): Builder
    {
        $query = User::query();

        // Filter by target type
        if ($campanha->target_type === 'vendor') {
            $query->whereHas('vendor');
        } elseif ($campanha->target_type === 'customer') {
            $query->whereDoesntHave('vendor');
        }
        // 'both' doesn't need filtering
        if ($campanha->target_type === 'vendor') {
            $query->whereHas('vendor', function ($q) use ($campanha) {
                if ($campanha->user_status === 'online') {
                    $q->where('status', 'Online');
                } elseif ($campanha->user_status === 'offline') {
                    $q->where('status', 'Offline');
                }
                // 'both' doesn't need filtering
            });
        }
        // Só quem tem dispositivo registado: sem token não há push nenhum, e
        // contá-los inflacionava o alcance da campanha com gente que nunca
        // poderia receber.
        $query->whereHas('devices');

        $this->filtrosDeEstado($campanha, $query);

        // Exclude opted-out users (global or per-campaign)
        $query->whereDoesntHave('notificationOptOuts', function ($q) use ($campanha) {
            $q->where(function ($inner) use ($campanha) {
                $inner->whereNull('notification_campaign_id')
                    ->orWhere('notification_campaign_id', $campanha->id);
            });
        });

        return $query;
    }

    private function filtraElegibilidade(NotificationCampaign $campanha): bool
    {
        return in_array($campanha->target_type, ['vendor', 'both'], true)
            && filled($campanha->vendor_eligibility);
    }

    /**
     * Filtros de estado que dao para fazer em SQL.
     *
     * Existem para as campanhas que valem a pena: falar com quem esta encravado
     * nalgum sitio concreto, em vez de com toda a gente.
     */
    private function filtrosDeEstado(NotificationCampaign $campanha, Builder $query): void
    {
        // Tecnicos sem morada de agendamento. Enquanto nao a tiverem, a
        // distancia — e logo o preco — dos servicos agendados sai da morada
        // fiscal, que pode ser o escritorio do contabilista.
        if ($campanha->vendor_missing_schedule_address) {
            $query->whereDoesntHave('addresses', function ($q) {
                $q->where('address_type', AddressType::SCHEDULE_ADDRESS);
            });
        }

        // Clientes que se registaram e nunca pediram nada.
        if ($campanha->customer_never_requested) {
            $query->whereDoesntHave('services');
        }

        // Sem servicos ha N dias. Vale para os dois lados: um cliente conta
        // pelos servicos que pediu, um tecnico pelos que executou — por isso
        // nao chega olhar para uma relacao so.
        if ($dias = $campanha->inactive_days) {
            $desde = now()->subDays($dias);

            $query->whereDoesntHave('services', fn ($q) => $q->where('services.created_at', '>=', $desde))
                ->whereDoesntHave('vendor.services', fn ($q) => $q->where('services.created_at', '>=', $desde));
        }
    }
}
