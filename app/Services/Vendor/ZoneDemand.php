<?php

namespace App\Services\Vendor;

use App\Models\Service;
use App\Models\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Procura recente nas zonas escolhidas pelo técnico.
 *
 * Serve o "estás a perder isto": mostrar a um técnico com o perfil a meio
 * quantos pedidos passaram pela zona dele. É a mesma ideia já usada na
 * auto-aceitação ("passaram-te ao lado X €"), aplicada à ativação.
 *
 * Conta PEDIDOS DA ZONA, não pedidos que lhe foram enviados: um perfil
 * incompleto nunca recebe nenhum, por isso a contagem seria sempre zero.
 * A cidade sai do JSON `services.address` (ver Service::formatVendorAddress).
 */
class ZoneDemand
{
    /** Janela de observação, em dias. */
    public const WINDOW_DAYS = 7;

    /**
     * Nº de serviços criados na janela nas zonas ativas do técnico.
     * Devolve 0 quando o técnico ainda não escolheu zonas — nesse caso não há
     * nada de honesto a dizer-lhe sobre "a tua zona".
     */
    public function recentRequestCount(Vendor $vendor): int
    {
        $cities = $vendor->allowedZones()->pluck('city')->filter()->unique();

        if ($cities->isEmpty()) {
            return 0;
        }

        return Service::query()
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            // `is_test` marca os serviços semeados para demonstração: não podem
            // inflacionar um número que serve para convencer alguém.
            ->where(fn ($q) => $q->whereNull('is_test')->orWhere('is_test', false))
            ->whereIn(
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(address, '$.city'))"),
                $cities->all()
            )
            ->count();
    }
}
