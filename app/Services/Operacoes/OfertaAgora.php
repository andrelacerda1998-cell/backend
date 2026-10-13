<?php

namespace App\Services\Operacoes;

use App\Enums\Vendors\StatusVendor;
use App\Models\GeneralSettings\City;
use App\Models\Vendor;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Quem está Online agora, e o que cada um pode mesmo fazer.
 *
 * Três degraus, os das Operações ao vivo desde o início:
 *
 *  - online: o interruptor da app ligado. Mais nada;
 *  - pode_aceitar: e cumpre tudo o que o matching exige para o convidar
 *    (documentos, IBAN, workspace, a AT quando já é exigida, sem outro
 *    serviço aberto) — o `can_accept_service`;
 *  - pronto: e mandou a localização dentro da janela da procura imediata.
 *    Sem posição recente, "Pedir agora" não o encontra.
 *
 * Com as cidades (as do catálogo que ele escolheu, a área de trabalho no
 * matching) e as categorias (as áreas dos tipos de serviço que faz). Quem
 * não escolheu cidades não tem restrição no matching — só o raio máximo — e
 * fica com a lista vazia: contá-lo em todas as cidades inventava oferta.
 *
 * Só os Online, de propósito: o `can_accept_service` custa umas consultas
 * por técnico, e são os Online que interessam a quem olha para a oferta.
 */
final class OfertaAgora
{
    /**
     * @return Collection<int, array{vendor: Vendor, pode_aceitar: bool, pronto: bool, areas: list<int>, cidades: list<string>, ultima_posicao: ?CarbonInterface}>
     */
    public static function online(bool $testes): Collection
    {
        $limite = now()->subMinutes(self::janelaMinutos());

        return Vendor::query()
            ->where('status', StatusVendor::ONLINE)
            ->whereHas('user', fn ($q) => $q->where('is_test', $testes))
            ->with(['currentLocation', 'servicesTypes', 'user', 'availableCities'])
            ->get()
            ->map(function (Vendor $v) use ($limite) {
                $podeAceitar = (bool) $v->can_accept_service;
                $posicao = $v->currentLocation?->updated_at;

                return [
                    'vendor' => $v,
                    'pode_aceitar' => $podeAceitar,
                    'pronto' => $podeAceitar && (bool) $posicao?->gte($limite),
                    'areas' => $v->servicesTypes->pluck('operation_area_id')->filter()->unique()
                        ->map(fn ($area) => (int) $area)->values()->all(),
                    // Por ordem alfabética: a base de dados não promete ordem nenhuma.
                    'cidades' => $v->availableCities->pluck('name')->filter()->unique()
                        ->sortBy(fn ($nome) => City::normalizar((string) $nome))->values()->all(),
                    'ultima_posicao' => $posicao,
                ];
            });
    }

    /** A janela da procura imediata (a mesma do `AvisarOnlineSemLocalizacaoCommand`). */
    public static function janelaMinutos(): int
    {
        return (int) config('services.request.location_update_threshold', 60);
    }
}
