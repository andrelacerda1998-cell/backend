<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Clientes — migrado do Filament (App\Filament\Resources\CustomerResource).
 *
 * NÃO existe um model `Customer` próprio: "clientes" são linhas de `User` sem
 * papel admin/super-admin/dev e sem um `Vendor` associado (mesmos filtros do
 * `table()`/`getEloquentQuery()` do CustomerResource). Como a `admin.api`
 * middleware usa um token estático partilhado (sem sessão/utilizador
 * autenticado), não há como replicar o ramo "developer vê também is_test" do
 * Filament -- aplica-se sempre o `is_test = false`, que é o comportamento por
 * omissão para não-developers.
 *
 * Ações destrutivas/sensíveis do Filament (ForceDeleteAction, reset de
 * password, geração de código de impersonação) ficam de fora desta fatia por
 * decisão explícita -- só index/block/restore.
 */
class CustomerController extends Controller
{
    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);
        $search = trim((string) $request->string('search'));
        $blockedOnly = $request->boolean('blocked');

        $query = User::query()
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['admin', 'super-admin', 'dev']))
            ->whereDoesntHave('vendor')
            ->where('is_test', false);

        if ($blockedOnly) {
            $query->onlyTrashed();
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('nif', 'like', "%{$search}%");
            });
        }

        $customers = $query->orderByDesc('created_at')->paginate($perPage);

        return ApiSuccessResponse::make([
            'items' => collect($customers->items())->map($this->present(...))->all(),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'total' => $customers->total(),
            ],
        ]);
    }

    /**
     * Bloquear = soft-delete real do User (mesma coluna `deleted_at` que o
     * TrashedFilter do Filament já usa). Sem conceito de "bloqueado" nativo no
     * Laravel -- reaproveita-se o soft-delete, que já remove o cliente das
     * listagens normais e (via os relacionamentos RESTRICT) do resto do sistema.
     */
    public function block(int $id): ApiSuccessResponse|ApiErrorResponse
    {
        $user = User::withTrashed()->find($id);

        if (! $user) {
            return new ApiErrorResponse(null, 'Cliente não encontrado.', 404);
        }

        if ($user->trashed()) {
            return new ApiErrorResponse(null, 'Este cliente já está bloqueado.', 409);
        }

        $user->delete();

        return ApiSuccessResponse::make($this->present($user->fresh()));
    }

    public function restore(int $id): ApiSuccessResponse|ApiErrorResponse
    {
        $user = User::withTrashed()->find($id);

        if (! $user) {
            return new ApiErrorResponse(null, 'Cliente não encontrado.', 404);
        }

        if (! $user->trashed()) {
            return new ApiErrorResponse(null, 'Este cliente não está bloqueado.', 409);
        }

        $user->restore();

        return ApiSuccessResponse::make($this->present($user->fresh()));
    }

    private function present(User $user): array
    {
        // NÃO usar user->name -- ver nota em SystemProfitController/
        // VendorDocumentController/VendorPaymentController sobre
        // User::setNameAttribute() nunca gravar a coluna 'name'.
        return [
            'id' => $user->id,
            'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: null,
            'nif' => $user->nif,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            // Derivado diretamente dos timestamps -- o CustomerResource chama
            // $record->hasVerifiedEmail(), mas esse método não existe em lado
            // nenhum do model User nem de nenhuma trait usada (MustVerifyEmail
            // está comentado no import); não replicado aqui de propósito.
            'email_verified' => $user->email_verified_at !== null,
            'phone_verified' => $user->phone_number_verified_at !== null,
            'can_request_service' => (bool) $user->can_request_service,
            'blocked_at' => $user->deleted_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
