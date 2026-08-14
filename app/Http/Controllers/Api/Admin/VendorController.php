<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\AllowedZone;
use App\Models\Service;
use App\Models\Vendor;
use App\Models\Vendor\VendorDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    /**
     * Indicadores da aba "Visão geral" -- só o que dá para calcular a partir
     * de dados reais. Substitui os "estados" fictícios do mock (aprovado/
     * disponivel/ativo/em_validacao/perfil_incompleto/suspenso), que não
     * existem no Laravel, por sinais reais equivalentes:
     * - "eligible" = App\Filament\Widgets\Vendors\VendorStats::eligibleVendorCount()
     *   (mesma lógica híbrida SQL+PHP, aqui sem o ramo "developer" porque a
     *   admin.api não distingue papéis) -- é o que o Filament chama
     *   "Podem aceitar serviço".
     * - "online" = StatusVendor::ONLINE (não "ativos nos últimos 30 dias",
     *   que não existe como conceito real).
     * - "docComplete" = Vendor::allDocumentsVerified (accessor real).
     * - "inValidation" = tem pelo menos um documento 'pending' em
     *   vendor_documents (aproximação -- não há um estado "em validação"
     *   dedicado, só o estado por documento).
     * - "avgApprovalTime" fica de fora de propósito: vendor_documents só tem
     *   created_at/updated_at, sem um campo "revisto em" -- sem sinal fiável
     *   (decisão do utilizador, mesmo princípio de "vazio em vez de inventar"
     *   já aplicado em CustomerController::bySource()/retention()).
     */
    public function metrics(): ApiSuccessResponse
    {
        $registered = $this->baseQuery()->count();
        $newThisMonth = $this->baseQuery()->where('created_at', '>=', now()->startOfMonth())->count();
        $online = $this->baseQuery()->where('status', StatusVendor::ONLINE)->count();

        $eligibleIds = $this->eligibleVendorIds();
        $eligible = $eligibleIds->count();

        $withClosedService = Service::query()
            ->whereIn('vendor_id', $eligibleIds)
            ->where('status', ServiceStatus::CLOSED)
            ->where('is_test', false)
            ->distinct()
            ->pluck('vendor_id');
        $noServices = $eligibleIds->count() - $withClosedService->count();

        $vendors = $this->baseQuery()->get();
        $docComplete = $vendors->filter(fn (Vendor $v) => $v->all_documents_verified)->count();

        $inValidation = VendorDocuments::query()
            ->where('status', 'pending')
            ->whereHas('vendor.user', fn ($q) => $q->where('is_test', false))
            ->distinct('vendor_id')
            ->count('vendor_id');

        // Tempo médio (dias) entre o registo do vendor e o seu primeiro
        // serviço (qualquer estado -- "primeira atividade", não "primeiro
        // serviço concluído"), só sobre quem já tem pelo menos um serviço.
        $firstServiceByVendor = Service::query()
            ->whereIn('vendor_id', $vendors->pluck('id'))
            ->where('is_test', false)
            ->orderBy('created_at')
            ->get(['vendor_id', 'created_at'])
            ->groupBy('vendor_id')
            ->map(fn ($g) => $g->first()->created_at);
        $vendorsById = $vendors->keyBy('id');
        $daysToFirstService = $firstServiceByVendor->map(function ($firstServiceAt, $vendorId) use ($vendorsById) {
            $vendor = $vendorsById->get($vendorId);

            return $vendor ? $vendor->created_at->diffInDays($firstServiceAt) : null;
        })->filter(fn ($d) => $d !== null);

        return ApiSuccessResponse::make([
            'registered' => $registered,
            'newThisMonth' => $newThisMonth,
            'eligible' => $eligible,
            'online' => $online,
            'docComplete' => $docComplete,
            'inValidation' => $inValidation,
            'noServices' => $noServices,
            'approvalRate' => $registered > 0 ? round($eligible / $registered * 100, 1) : 0,
            'profileCompletionRate' => $registered > 0 ? round($docComplete / $registered * 100, 1) : 0,
            'avgTimeToFirstService' => $daysToFirstService->count() > 0 ? round($daysToFirstService->avg(), 1) : 0,
        ]);
    }

    /**
     * Mapa ao vivo -- só informativo, sem qualquer efeito no fluxo de
     * pedidos/matching (esse continua inteiramente na app). Mostra só
     * técnicos com status=Online E localização atualizada recentemente: a
     * app-vendor só envia GPS (PUT /vendor/location/update, a cada ~7.5s)
     * enquanto o técnico está "Online" ou com um serviço aceite (ver
     * LocationContext.tsx / home/index.tsx da app-vendor) -- por isso
     * "Online" sem localização recente é sinal de app em segundo plano/sem
     * rede, não presença real, e fica de fora para não mostrar um pin preso
     * num sítio antigo.
     *
     * `include_test=1` inclui vendors de utilizadores marcados como teste
     * (excluídos por omissão, como em toda a `baseQuery()`) -- só para dar
     * ao staff uma forma de validar o mapa sem depender de um técnico real
     * estar online; o valor por omissão continua a mostrar só dados reais.
     */
    public function liveLocations(Request $request): ApiSuccessResponse
    {
        $includeTest = $request->boolean('include_test');

        $query = $includeTest ? Vendor::query() : $this->baseQuery();

        $vendors = $query
            ->where('status', StatusVendor::ONLINE)
            ->whereHas('currentLocation', fn ($q) => $q->where('updated_at', '>=', now()->subMinutes(10)))
            ->with(['user', 'currentLocation', 'servicesTypes'])
            ->get();

        return ApiSuccessResponse::make(
            $vendors->map($this->presentLiveLocation(...))->all()
        );
    }

    private function presentLiveLocation(Vendor $vendor): array
    {
        $user = $vendor->user;
        $location = $vendor->currentLocation;

        return [
            'id' => $vendor->id,
            'name' => $user ? (trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: null) : null,
            'is_test' => (bool) ($user->is_test ?? false),
            'latitude' => $location ? (float) $location->latitude : null,
            'longitude' => $location ? (float) $location->longitude : null,
            'updated_at' => $location?->updated_at?->toIso8601String(),
            'categories' => $vendor->servicesTypes->pluck('name')->all(),
        ];
    }

    /**
     * IDs dos vendors elegíveis (Vendor::canAcceptService) dentro do âmbito
     * normal (sem is_test). Mesma abordagem híbrida do VendorStats do
     * Filament: pré-filtrar em SQL as condições baratas para reduzir o
     * conjunto, só depois avaliar o accessor (que exige PHP) nos candidatos.
     */
    private function eligibleVendorIds()
    {
        $candidates = $this->baseQuery()
            ->whereNotNull('iban')
            ->where('invoice_workspace', '!=', '')
            ->where('at_valid', true)
            ->where('at_user', 'like', '%/%')
            ->whereDoesntHave('services', fn ($q) => $q
                ->whereIn('status', [ServiceStatus::ACCEPTED, ServiceStatus::FINISHED, ServiceStatus::ARRIVED])
                ->whereIn('payment_status', [PaymentStatus::PAID, PaymentStatus::PENDING])
                ->whereDoesntHave('schedule'))
            ->get();

        return $candidates->filter->can_accept_service->pluck('id');
    }

    /**
     * Técnicos por categoria -- conta cada vendor nas áreas de operação
     * (App\Models\GeneralSettings\OperationArea, ex.: "Canalização",
     * "Eletricista") para que está registado. É a qualificação/oferta
     * registada, não trabalho realmente feito -- decisão do utilizador.
     *
     * NOTA: "operation_areas" é o nome de coluna já usado em present() para
     * este mesmo relacionamento, mas representa CATEGORIAS/OFÍCIOS, não
     * zonas geográficas -- a geografia real está em AllowedZone (ver
     * byLocation() abaixo). O rótulo "Zonas" usado na Lista estava errado
     * e foi corrigido para "Categorias" no mesmo commit desta funcionalidade.
     */
    public function byCategory(): ApiSuccessResponse
    {
        $counts = [];
        $this->baseQuery()->with('operationAreas')->get()->each(function (Vendor $vendor) use (&$counts) {
            foreach ($vendor->operationAreas as $area) {
                $counts[$area->name] = ($counts[$area->name] ?? 0) + 1;
            }
        });

        return ApiSuccessResponse::make(
            collect($counts)->map(fn ($value, $name) => ['name' => $name, 'value' => $value])->values()->all()
        );
    }

    /**
     * Técnicos por localização -- conta cada vendor nas zonas de cobertura
     * que declarou (App\Models\GeneralSettings\AllowedZone), não a morada
     * fiscal/de agendamentos. Um vendor com várias zonas conta em cada uma.
     */
    public function byLocation(): ApiSuccessResponse
    {
        $counts = [];
        $this->baseQuery()->with('allowedZones')->get()->each(function (Vendor $vendor) use (&$counts) {
            foreach ($vendor->allowedZones as $zone) {
                $counts[$zone->city] = ($counts[$zone->city] ?? 0) + 1;
            }
        });

        return ApiSuccessResponse::make(
            collect($counts)->map(fn ($value, $name) => ['name' => $name, 'value' => $value])
                ->sortByDesc('value')->values()->all()
        );
    }

    /**
     * Top técnicos por receita gerada para a Piquet (amount - amount_for_vendor,
     * a comissão), só sobre serviços concluídos reais. amountReceived é o
     * valor pago ao próprio técnico (amount_for_vendor). Nem "amount" nem
     * "amount_for_vendor" têm um Attribute mutator (ao contrário de
     * price_rate) -- são colunas em cêntimos simples, SUM() direto é seguro.
     */
    public function top(Request $request): ApiSuccessResponse
    {
        $limit = min((int) $request->integer('limit', 10), 50);
        $vendorIds = $this->baseQuery()->pluck('id');

        $rows = Service::query()
            ->whereIn('vendor_id', $vendorIds)
            ->where('status', ServiceStatus::CLOSED)
            ->where('is_test', false)
            ->selectRaw('vendor_id, COUNT(*) as services_completed, SUM(amount_for_vendor) as amount_received, SUM(amount - amount_for_vendor) as amount_generated, AVG(rating_by_vendor) as avg_rating')
            ->groupBy('vendor_id')
            ->orderByDesc('amount_generated')
            ->limit($limit)
            ->get();

        $vendors = Vendor::whereIn('id', $rows->pluck('vendor_id'))->get()->keyBy('id');

        return ApiSuccessResponse::make($rows->map(function ($row) use ($vendors) {
            $vendor = $vendors->get($row->vendor_id);
            $user = $vendor?->user;

            return [
                'id' => $row->vendor_id,
                'name' => $user ? (trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: null) : null,
                'servicesCompleted' => (int) $row->services_completed,
                'averageRating' => $row->avg_rating !== null ? round((float) $row->avg_rating, 2) : 0,
                'piquetRevenue' => round(((int) $row->amount_generated) / 100, 2),
                'amountReceived' => round(((int) $row->amount_received) / 100, 2),
            ];
        })->values()->all());
    }

    /**
     * Procura vs oferta por zona -- oferta = vendors cujas zonas de cobertura
     * declaradas (AllowedZone) incluem essa cidade; procura = pedidos de
     * serviço reais nessa cidade (Service.address->city, coluna JSON).
     * Limitado às 20 cidades com mais procura para o heatmap não ficar
     * ilegível (há dezenas de AllowedZone na BD).
     */
    public function coverage(): ApiSuccessResponse
    {
        $supplyByZoneId = DB::table('vendor_allowed_zones')
            ->join('vendors', 'vendors.id', '=', 'vendor_allowed_zones.vendor_id')
            ->join('users', 'users.id', '=', 'vendors.user_id')
            ->where('users.is_test', false)
            ->whereNull('vendors.deleted_at')
            ->selectRaw('vendor_allowed_zones.allowed_zone_id, COUNT(DISTINCT vendor_allowed_zones.vendor_id) as supply')
            ->groupBy('vendor_allowed_zones.allowed_zone_id')
            ->pluck('supply', 'allowed_zone_id');

        $supplyByCity = [];
        AllowedZone::select('id', 'city')->get()->each(function ($zone) use (&$supplyByCity, $supplyByZoneId) {
            $supplyByCity[$zone->city] = ($supplyByCity[$zone->city] ?? 0) + (int) ($supplyByZoneId[$zone->id] ?? 0);
        });

        $demandByCity = Service::query()
            ->where('is_test', false)
            ->whereNotNull('address')
            ->selectRaw('JSON_UNQUOTE(JSON_EXTRACT(address, \'$.city\')) as city, COUNT(*) as total')
            ->groupBy('city')
            ->pluck('total', 'city');

        $cities = collect($supplyByCity)->keys()->merge($demandByCity->keys())->unique()->filter();

        $rows = $cities->map(function ($city) use ($supplyByCity, $demandByCity) {
            $supply = $supplyByCity[$city] ?? 0;
            $demand = (int) ($demandByCity[$city] ?? 0);

            return [
                'name' => $city,
                'procura' => $demand,
                'oferta' => $supply,
                'ratio' => $supply > 0 ? round($demand / $supply, 2) : $demand,
            ];
        })->sortByDesc('procura')->take(20)->values();

        return ApiSuccessResponse::make($rows->all());
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
