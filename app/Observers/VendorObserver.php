<?php

namespace App\Observers;

use App\Enums\Schedule\ScheduleDay;
use App\Models\Schedule\ScheduleAvailable;
use App\Models\Schedule\ScheduleDays;
use App\Models\Vendor;
use App\Models\VendorScheduleSearch;

class VendorObserver
{
    public function saved(Vendor $vendor): void
    {
        $this->limparPrazoDaAtSeJaFoiDada($vendor);

        $vendor->searchable();

        // Also update the schedule search index
        $this->updateScheduleSearchIndex($vendor);
    }

    /**
     * O relógio dos 5 dias pára assim que a AT chega.
     *
     * Aqui e não em cada sítio que grava a AT: ela entra pela app do técnico,
     * pelo backoffice e por comandos de manutenção, e um caminho esquecido
     * deixava o prazo a correr contra alguém que já o cumpriu -- e a perder o
     * dinheiro por isso.
     *
     * `saved` e não `updated` porque o `at_valid` pode vir já certo na criação.
     */
    private function limparPrazoDaAtSeJaFoiDada(Vendor $vendor): void
    {
        // Só quando os campos da AT mudaram: poupa uma leitura em cada gravação
        // de vendor, que são muitas (o índice de pesquisa escreve a cada uma).
        if (! $vendor->wasChanged(['at_user', 'at_valid'])) {
            return;
        }

        /*
         * Instância NOVA e não `$vendor`.
         *
         * O `at_ready` é um acessor com `shouldCache()`, e nesta instância já
         * foi calculado ANTES da gravação -- lê `false` mesmo depois de a AT
         * chegar, e o prazo continuava a correr contra quem já o cumpriu. Uma
         * leitura fresca custa uma query e evita alguém perder dinheiro por um
         * valor em cache.
         */
        $atual = $vendor->fresh();

        if ($atual?->at_deadline_started_at !== null && $atual->at_ready) {
            $atual->limparPrazoDaAt();
        }
    }

    /**
     * Update the VendorScheduleSearch index for schedule-based searches.
     */
    private function updateScheduleSearchIndex(Vendor $vendor): void
    {
        $vendorScheduleSearch = VendorScheduleSearch::find($vendor->id);
        if ($vendorScheduleSearch) {
            $vendorScheduleSearch->searchable();
        }
    }

    /*    public function updated(Vendor $vendor): void
        {
            $vendor->updateRatting();
            $vendor->refresh();
            $vendor->load('servicesTypes', 'averageRating');
            $vendor->searchable();
        }*/

    public function created(Vendor $vendor): void
    {
        $days = ScheduleDays::query()->pluck('id', 'day_name')->toArray();
        $weekendDays = [ScheduleDay::SATURDAY->value, ScheduleDay::SUNDAY->value];

        foreach ($days as $dayName => $dayId) {
            ScheduleAvailable::query()->create([
                'vendor_id' => $vendor->id,
                'day_id' => $dayId,
                // Desligada por omissão: a auto-aceitação responde por ele aos
                // pedidos de serviço, e isso é uma escolha que tem de ser dele.
                // Nascer ligada fazia toda a gente aceitar tudo sem nunca o ter
                // decidido — e a etapa de resposta perdia sentido.
                'auto_accept' => false,
                'is_enabled' => ! in_array($dayName, $weekendDays),
            ]);
        }
    }
}
