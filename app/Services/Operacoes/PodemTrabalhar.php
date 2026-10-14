<?php

namespace App\Services\Operacoes;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Quem pode receber pedidos: o `can_accept_service`, técnico a técnico.
 *
 * É o número a que o backoffice chama "Prontos a trabalhar" e a que as
 * contagens por zona e por categoria chamam "podem trabalhar". Falta-lhes só
 * o que muda a cada pedido: estar Online (o interruptor é deles), fazer aquele
 * tipo de serviço e estar na área.
 *
 * Uma regra, um sítio. Havia duas cópias, no VendorController e no widget do
 * Filament, cada uma com o seu pré-filtro em SQL, e ficaram para trás em
 * alturas diferentes: a AT a 30/09 (o backoffice mostrava 41 elegíveis onde
 * havia 69) e o IBAN e a conta de faturação a 13/10, que o widget ainda exigia.
 */
final class PodemTrabalhar
{
    /**
     * @param  Builder<Vendor>|null  $tecnicos  o universo; por omissão, os técnicos reais (sem contas de teste)
     * @return Collection<int, int>
     */
    public static function ids(?Builder $tecnicos = null): Collection
    {
        $tecnicos = $tecnicos
            ? clone $tecnicos
            : Vendor::query()->whereHas('user', fn ($q) => $q->where('is_test', false));

        /*
         * O PRÉ-FILTRO EM SQL SÓ PODE CONTER O QUE `canAcceptService` EXIGE
         * SEMPRE. Serve para não carregar 460 técnicos e avaliar o atributo em
         * cada um -- é performance, não é a regra.
         *
         * Tinha `at_valid = true` e `at_user like '%/%'`. Era verdade até
         * 30/09/2026, quando a AT passou a ser exigida só a partir do quarto
         * serviço (`at_ready || ! at_required`). O filtro ficou para trás, e
         * passou a ESCONDER técnicos que já podem trabalhar.
         *
         * A lição não é "faltou atualizar": é que duplicar uma regra em SQL
         * para ir mais depressa cria uma segunda cópia que ninguém se lembra
         * de mudar. O que sobra aqui são as condições que não dependem de
         * contagens nem de datas.
         *
         * A 13/10/2026 o IBAN e a conta de faturação passaram a seguir a AT
         * (só a partir do quarto serviço, ver `Vendor::faturacaoEmDia`), e
         * saíram daqui pela mesma razão.
         */
        return $tecnicos
            ->whereDoesntHave('services', fn ($q) => $q
                ->whereIn('status', [ServiceStatus::ACCEPTED, ServiceStatus::FINISHED, ServiceStatus::ARRIVED])
                ->whereIn('payment_status', [PaymentStatus::PAID, PaymentStatus::PENDING])
                ->whereDoesntHave('schedule'))
            ->get()
            ->filter->can_accept_service
            ->map(fn (Vendor $v) => (int) $v->id)
            ->values();
    }
}
