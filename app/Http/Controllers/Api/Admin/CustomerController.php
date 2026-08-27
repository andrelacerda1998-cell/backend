<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Address;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
    /**
     * Mesmos filtros do CustomerResource (roles/vendor/is_test) partilhados por
     * index()/metrics()/byLocation()/bySource()/trend() -- só non-blocked
     * (não-trashed) por omissão, tal como o separador "Todos" da Lista.
     */
    private function baseQuery(): Builder
    {
        return User::query()
            ->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['admin', 'super-admin', 'dev']))
            ->whereDoesntHave('vendor')
            ->where('is_test', false);
    }

    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);
        $search = trim((string) $request->string('search'));
        $blockedOnly = $request->boolean('blocked');

        $query = $this->baseQuery();

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

        $customers = $query->with('mainAddressRelation')->orderByDesc('created_at')->paginate($perPage);

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

    /**
     * Indicadores da aba "Visão geral" -- só o que dá para calcular a partir de
     * dados reais. "Serviço concluído" = status CLOSED e sem testes, mesma
     * definição usada por Vendor::rating() e pelos widgets de estatísticas do
     * Filament (ServiceStatusStats). Sem reclamações (não existe o conceito no
     * Laravel nem no Filament) -- withComplaints fica sempre 0.
     */
    public function metrics(): ApiSuccessResponse
    {
        $customerIds = $this->baseQuery()->pluck('id');
        $total = $customerIds->count();
        $newCustomers = $this->baseQuery()->where('created_at', '>=', now()->subDays(30))->count();

        $services = Service::query()
            ->whereIn('customer_id', $customerIds)
            ->where('status', ServiceStatus::CLOSED)
            ->where('is_test', false)
            ->orderBy('created_at')
            ->get(['customer_id', 'price_rate', 'rating_by_customer', 'created_at']);

        $byCustomer = $services->groupBy('customer_id');
        $oneTime = 0;
        $recurring = 0;
        $secondServiceDays = [];

        foreach ($byCustomer as $customerServices) {
            $count = $customerServices->count();
            if ($count === 1) {
                $oneTime++;
            } elseif ($count >= 2) {
                $recurring++;
                $ordered = $customerServices->values();
                $secondServiceDays[] = $ordered[0]->created_at->diffInDays($ordered[1]->created_at);
            }
        }

        // price_rate está em cêntimos na coluna; o accessor priceRate() do
        // model formata em euros (string) -- getRawOriginal() para somar em
        // cêntimos sem passar pelo accessor.
        $totalRevenueCents = $services->sum(fn (Service $s) => (int) $s->getRawOriginal('price_rate'));
        $ratings = $services->pluck('rating_by_customer')->filter(fn ($r) => $r !== null)->map(fn ($r) => (float) $r);

        $withService = $byCustomer->count();
        $inactive = max(0, $total - $withService);
        $avgRevenuePerCustomer = $total > 0 ? ($totalRevenueCents / 100) / $total : 0;
        $repurchaseRate = $total > 0 ? ($recurring / $total) * 100 : 0;

        return ApiSuccessResponse::make([
            'registered' => $total,
            'newCustomers' => $newCustomers,
            'active' => $withService,
            'recurring' => $recurring,
            'oneTime' => $oneTime,
            'inactive' => $inactive,
            'repurchaseRate' => round($repurchaseRate, 1),
            'avgServicesPerCustomer' => $total > 0 ? round($services->count() / $total, 2) : 0,
            'avgRevenuePerCustomer' => round($avgRevenuePerCustomer, 2),
            // Estimativa (não é medição direta): 2.5x a receita média já gerada
            // por cliente -- mesma heurística que a versão fictícia usava,
            // agora aplicada sobre receita real.
            'estimatedLTV' => round($avgRevenuePerCustomer * 2.5, 2),
            'avgTimeToSecondService' => count($secondServiceDays) > 0 ? round(array_sum($secondServiceDays) / count($secondServiceDays), 1) : 0,
            'averageRating' => $ratings->count() > 0 ? round($ratings->avg(), 2) : 0,
            'withComplaints' => 0,
        ]);
    }

    public function byLocation(): ApiSuccessResponse
    {
        $customerIds = $this->baseQuery()->pluck('id');

        $rows = Address::query()
            ->whereIn('user_id', $customerIds)
            ->where('main_address', true)
            ->whereNotNull('city')
            ->selectRaw('city, COUNT(*) as total')
            ->groupBy('city')
            ->orderByDesc('total')
            ->get();

        return ApiSuccessResponse::make(
            $rows->map(fn ($r) => ['name' => $r->city, 'value' => (int) $r->total])->all()
        );
    }

    /**
     * Sem tracking de canal/origem de aquisição no Laravel (nenhuma coluna do
     * género em User) -- devolve vazio de propósito, em vez de inventar uma
     * distribuição por origem.
     */
    public function bySource(): ApiSuccessResponse
    {
        return ApiSuccessResponse::make([]);
    }

    /**
     * Novos clientes vs clientes recorrentes por mês (últimos 6 meses). "Novo"
     * = registo nesse mês; "recorrente" = serviço concluído nesse mês por um
     * cliente cujo primeiro serviço concluído foi antes desse mês.
     */
    public function trend(): ApiSuccessResponse
    {
        $months = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i)->startOfMonth());
        $customerIds = $this->baseQuery()->pluck('id');

        $services = Service::query()
            ->whereIn('customer_id', $customerIds)
            ->where('status', ServiceStatus::CLOSED)
            ->where('is_test', false)
            ->orderBy('created_at')
            ->get(['customer_id', 'created_at']);

        $firstServiceAt = $services->groupBy('customer_id')->map(fn ($g) => $g->first()->created_at);
        $ptMonths = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

        $data = $months->map(function (Carbon $monthStart) use ($services, $firstServiceAt, $ptMonths) {
            $monthEnd = $monthStart->copy()->endOfMonth();

            $novos = $this->baseQuery()
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->count();

            $recorrentes = $services->filter(function (Service $s) use ($monthStart, $monthEnd, $firstServiceAt) {
                return $s->created_at->between($monthStart, $monthEnd)
                    && $firstServiceAt[$s->customer_id]->lt($monthStart);
            })->count();

            return [
                'name' => $ptMonths[$monthStart->month - 1],
                'novos' => $novos,
                'recorrentes' => $recorrentes,
            ];
        });

        return ApiSuccessResponse::make($data->values()->all());
    }

    /**
     * Retenção por coorte: não implementada (sem análise de coortes no
     * Laravel) -- devolve vazio de propósito, em vez das barras fictícias
     * antigas (42/35/28%).
     */
    public function retention(): ApiSuccessResponse
    {
        return ApiSuccessResponse::make([]);
    }

    /**
     * Métodos de pagamento guardados — equivalente ao Filament
     * PaymentMethodsRelationManager (dentro do CustomerResource). Só listar +
     * apagar, sem criar/editar: cartões/MBWay são geridos pelo Payshop via
     * app, nunca por formulário manual no backoffice (o próprio Filament só
     * expõe DeleteAction aqui, o form de criar está comentado no código
     * original).
     *
     * O Filament restringe o apagar a super-admin, mas a admin.api usa um
     * token partilhado sem sessão/utilizador autenticado -- não há "quem" a
     * verificar (mesma razão já documentada em VendorController para não
     * replicar a restrição de super-admin no suspender/reativar).
     */
    public function paymentMethods(int $id): ApiSuccessResponse|ApiErrorResponse
    {
        $user = User::withTrashed()->find($id);

        if (! $user) {
            return new ApiErrorResponse(null, 'Cliente não encontrado.', 404);
        }

        // Desempate por 'id': dois métodos criados no mesmo segundo (comum em
        // testes, mas também possível em produção) não têm ordem garantida só
        // por 'created_at' -- mesmo padrão já usado em AuditController.
        $methods = $user->paymentMethods()->orderByDesc('created_at')->orderByDesc('id')->get();

        return ApiSuccessResponse::make([
            'items' => $methods->map($this->presentPaymentMethod(...))->all(),
        ]);
    }

    public function deletePaymentMethod(int $id, int $methodId): ApiSuccessResponse|ApiErrorResponse
    {
        $user = User::withTrashed()->find($id);

        if (! $user) {
            return new ApiErrorResponse(null, 'Cliente não encontrado.', 404);
        }

        $method = $user->paymentMethods()->find($methodId);

        if (! $method) {
            return new ApiErrorResponse(null, 'Método de pagamento não encontrado.', 404);
        }

        $method->delete();

        return ApiSuccessResponse::make(['deleted' => true]);
    }

    private function presentPaymentMethod($method): array
    {
        return [
            'id' => $method->id,
            'type' => $method->type,
            'brand' => $method->brand,
            'brand_description' => $method->brand_description,
            'last4' => $method->last4,
            'phone_number' => $method->phone_number,
            'holder' => $method->holder,
            'expire_month' => $method->expire_month,
            'expire_year' => $method->expire_year,
            'created_at' => $method->created_at?->toIso8601String(),
        ];
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
            // Cidade da morada principal do cliente (para a lista/base de dados
            // do backoffice). Eager-loaded via mainAddressRelation na index()
            // para não haver N+1; noutros usos (block/restore/single) resolve
            // com uma query pontual, aceitável fora de listagens.
            'city' => ($user->relationLoaded('mainAddressRelation')
                ? $user->mainAddressRelation?->city
                : $user->mainAddress()?->city) ?: null,
            'blocked_at' => $user->deleted_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}
