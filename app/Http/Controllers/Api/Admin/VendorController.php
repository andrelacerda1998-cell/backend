<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Vendor;
use Illuminate\Http\Request;

/**
 * Técnicos/Vendors — migrado do Filament (App\Filament\Resources\VendorResource).
 *
 * Como a `admin.api` middleware usa um token estático partilhado (sem sessão/
 * utilizador autenticado), não há como replicar o ramo "developer vê também
 * is_test" do Filament -- aplica-se sempre `user.is_test = false`, o
 * comportamento por omissão para não-developers.
 *
 * Ações restritas a super-admin no Filament (editar dados do vendor -- IBAN,
 * preço, NIF --, eliminar, "Marcar como Test", "Alterar serviços") ficam de
 * fora desta fatia por decisão explícita.
 *
 * IMPORTANTE sobre suspend()/restore(): no Filament, só o super-admin pode
 * mutar um vendor (VendorResource::canEdit()/canDelete()) -- "o IBAN
 * redireciona payouts, exposição direta de dinheiro se um admin simples
 * editar". A `admin.api` não distingue papéis (token único partilhado por
 * todo o staff com acesso ao backoffice Next.js), por isso suspender/reativar
 * aqui NÃO replica essa restrição -- decisão explícita (soft-delete é
 * reversível e menos sensível que editar IBAN/preço, mas fica documentado
 * que qualquer membro do staff com acesso ao backoffice consegue suspender
 * um vendor, não só super-admins).
 */
class VendorController extends Controller
{
    private function baseQuery()
    {
        return Vendor::query()
            ->whereHas('user')
            ->whereHas('user', fn ($q) => $q->where('is_test', false));
    }

    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);
        $search = trim((string) $request->string('search'));
        $suspendedOnly = $request->boolean('suspended');

        $query = $this->baseQuery();

        if ($suspendedOnly) {
            $query->onlyTrashed();
        }

        if ($search !== '') {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('nif', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        $vendors = $query->orderByDesc('created_at')->paginate($perPage);

        return ApiSuccessResponse::make([
            'items' => collect($vendors->items())->map($this->present(...))->all(),
            'meta' => [
                'current_page' => $vendors->currentPage(),
                'last_page' => $vendors->lastPage(),
                'per_page' => $vendors->perPage(),
                'total' => $vendors->total(),
            ],
        ]);
    }

    public function suspend(int $id): ApiSuccessResponse|ApiErrorResponse
    {
        $vendor = Vendor::withTrashed()->find($id);

        if (! $vendor) {
            return new ApiErrorResponse(null, 'Técnico não encontrado.', 404);
        }

        if ($vendor->trashed()) {
            return new ApiErrorResponse(null, 'Este técnico já está suspenso.', 409);
        }

        $vendor->delete();

        return ApiSuccessResponse::make($this->present($vendor->fresh()));
    }

    public function restore(int $id): ApiSuccessResponse|ApiErrorResponse
    {
        $vendor = Vendor::withTrashed()->find($id);

        if (! $vendor) {
            return new ApiErrorResponse(null, 'Técnico não encontrado.', 404);
        }

        if (! $vendor->trashed()) {
            return new ApiErrorResponse(null, 'Este técnico não está suspenso.', 409);
        }

        $vendor->restore();

        return ApiSuccessResponse::make($this->present($vendor->fresh()));
    }

    private function present(Vendor $vendor): array
    {
        // NÃO usar vendor->name / vendor->fullName / vendor->full_name -- todos
        // delegam em user->full_name, que não existe em lado nenhum do model
        // User (ver nota nos outros controllers de vendor/customer sobre
        // User::setNameAttribute() nunca gravar 'name'). first_name/last_name
        // do user são as colunas reais.
        $user = $vendor->user;

        return [
            'id' => $vendor->id,
            'name' => $user ? (trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: null) : null,
            'nif' => $user?->nif,
            'phone_number' => $user?->phone_number,
            // price_rate vem em cêntimos na coluna; o accessor priceRate() do
            // model devolve uma string formatada (com separador de milhares) --
            // getRawOriginal() para converter para euros sem passar por ele.
            'price_rate' => $vendor->getRawOriginal('price_rate') !== null
                ? round(((int) $vendor->getRawOriginal('price_rate')) / 100, 2)
                : null,
            'operation_areas' => $vendor->operationAreas->pluck('name')->all(),
            'can_accept_service' => (bool) $vendor->can_accept_service,
            'at_valid' => (bool) $vendor->at_valid,
            'at_validated_at' => $vendor->at_validated_at?->toIso8601String(),
            'status' => $vendor->status?->value,
            'suspended_at' => $vendor->deleted_at?->toIso8601String(),
            'created_at' => $vendor->created_at?->toIso8601String(),
        ];
    }
}
