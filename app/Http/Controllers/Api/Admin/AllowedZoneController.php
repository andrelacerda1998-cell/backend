<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreAllowedZoneRequest;
use App\Http\Requests\Api\Admin\UpdateAllowedZoneRequest;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\AllowedZone;
use Illuminate\Http\Request;

/**
 * Zonas — equivalente ao Filament AllowedZoneResource. Fatia "Lista + criar/
 * editar, sem apagar" (decisão do utilizador, 2026-07-29). Diferenças
 * deliberadas face ao Filament, todas decisões explícitas:
 *
 * - Sem apagar: o Filament tem DeleteAction/DeleteBulkAction, mas o modelo
 *   AllowedZone NÃO tem soft-deletes -- seria apagar a sério, sem rede de
 *   segurança. Fica de fora nesta fatia.
 * - Sem controlo de acesso: `AllowedZoneResource::canAccess()` restringe ao
 *   papel 'super-admin' no Filament; o backoffice Next.js ainda não tem
 *   perfis/permissões (mesmo critério já usado em Técnicos/suspender).
 * - 'city'/'district' são texto livre aqui; no Filament vêm de um
 *   autocomplete do Google Places (restrito a Portugal) que preenche as
 *   duas colunas a partir da morada escolhida -- não replicado por
 *   simplicidade (sem chave da API do Google Maps no Next.js).
 */
class AllowedZoneController extends Controller
{
    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 100), 200);

        $query = AllowedZone::query()->withCount('vendors')->orderBy('city');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('city', 'like', "%{$search}%")
                    ->orWhere('district', 'like', "%{$search}%");
            });
        }

        $zones = $query->paginate($perPage);

        return ApiSuccessResponse::make([
            'items' => collect($zones->items())->map($this->present(...))->all(),
            'meta' => [
                'current_page' => $zones->currentPage(),
                'last_page' => $zones->lastPage(),
                'per_page' => $zones->perPage(),
                'total' => $zones->total(),
            ],
        ]);
    }

    public function store(StoreAllowedZoneRequest $request): ApiSuccessResponse
    {
        $zone = AllowedZone::create($request->validated());

        return ApiSuccessResponse::make($this->present($zone->fresh()), statusCode: 201);
    }

    public function update(UpdateAllowedZoneRequest $request, AllowedZone $allowedZone): ApiSuccessResponse
    {
        $allowedZone->update($request->validated());

        return ApiSuccessResponse::make($this->present($allowedZone->fresh()));
    }

    private function present(AllowedZone $zone): array
    {
        return [
            'id' => $zone->id,
            'city' => $zone->city,
            'district' => $zone->district,
            'vendors_count' => $zone->vendors_count ?? 0,
            'created_at' => $zone->created_at?->toIso8601String(),
        ];
    }
}
