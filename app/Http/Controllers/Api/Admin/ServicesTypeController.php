<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreServicesTypeRequest;
use App\Http\Requests\Api\Admin\UpdateServicesTypeRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\ServicesType;
use Illuminate\Http\Request;

/**
 * Catálogo — equivalente ao Filament ServicesTypeResource. Fatia explicitamente
 * limitada a "Lista + criar/editar, sem apagar" (decisão do utilizador,
 * 2026-07-29). Ficam de fora desta fatia:
 *
 * - `image` (upload via Spatie MediaLibrary) -- sem campo de upload nesta
 *   versão do backoffice.
 * - `suggested_price` -- nem sequer tem campo no formulário do Filament (só
 *   filtro na tabela), por isso não faz sentido adicioná-lo aqui.
 * - restaurar um tipo apagado. Apagar existe, mas só para tipos que nunca
 *   tiveram pedidos; os outros desativam-se (`is_active`) -- ver destroy().
 *
 * `name`/`includes`/`excludes` são traduzíveis no Filament (campos EN + PT-PT
 * lado a lado). Esta fatia usa um único campo de texto por simplicidade -- o
 * valor introduzido é gravado em ambas as línguas (ver applyName/
 * toTranslatableArray). Quem precisar de gerir as duas línguas em separado
 * continua a poder usar o Filament.
 */
class ServicesTypeController extends Controller
{
    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 20), 100);

        $query = ServicesType::query()->with('operationArea')->withCount($this->contagens())->orderBy('id');

        if ($areaId = $request->integer('operation_area_id')) {
            $query->where('operation_area_id', $areaId);
        }

        // 'name' é armazenado como JSON traduzível -- o LIKE procura no blob
        // inteiro (encontra o termo em qualquer uma das línguas), aproximado
        // mas simples e suficiente para uma lista de poucas dezenas de tipos.
        if ($search = $request->string('search')->trim()->value()) {
            $query->where('name', 'like', "%{$search}%");
        }

        $types = $query->paginate($perPage);

        return ApiSuccessResponse::make([
            'items' => collect($types->items())->map($this->present(...))->all(),
            'meta' => [
                'current_page' => $types->currentPage(),
                'last_page' => $types->lastPage(),
                'per_page' => $types->perPage(),
                'total' => $types->total(),
            ],
        ]);
    }

    /** GET /v1/admin/services-types/{servicesType} — um tipo, com as contagens. */
    public function show(ServicesType $servicesType): ApiSuccessResponse
    {
        return ApiSuccessResponse::make($this->present($this->comContagens($servicesType)));
    }

    public function store(StoreServicesTypeRequest $request): ApiSuccessResponse
    {
        $data = $request->validated();

        $type = new ServicesType();
        $type->operation_area_id = $data['operation_area_id'];
        $type->time = $data['time'];
        $type->starts_from = $data['starts_from'] ?? null;
        $this->applyName($type, $data['name']);
        $type->includes = $this->toTranslatableArray($data['includes'] ?? []);
        $type->excludes = $this->toTranslatableArray($data['excludes'] ?? []);
        $type->save();

        return ApiSuccessResponse::make($this->present($type->fresh(['operationArea'])), statusCode: 201);
    }

    public function update(UpdateServicesTypeRequest $request, ServicesType $servicesType): ApiSuccessResponse
    {
        $data = $request->validated();

        if (array_key_exists('operation_area_id', $data)) {
            $servicesType->operation_area_id = $data['operation_area_id'];
        }
        if (array_key_exists('time', $data)) {
            $servicesType->time = $data['time'];
        }
        if (array_key_exists('starts_from', $data)) {
            $servicesType->starts_from = $data['starts_from'];
        }
        if (array_key_exists('name', $data)) {
            $this->applyName($servicesType, $data['name']);
        }
        if (array_key_exists('includes', $data)) {
            $servicesType->includes = $this->toTranslatableArray($data['includes'] ?? []);
        }
        if (array_key_exists('excludes', $data)) {
            $servicesType->excludes = $this->toTranslatableArray($data['excludes'] ?? []);
        }
        if (array_key_exists('is_active', $data)) {
            $servicesType->is_active = (bool) $data['is_active'];
        }
        $servicesType->save();

        return ApiSuccessResponse::make($this->present($this->comContagens($servicesType->fresh())));
    }

    /**
     * DELETE /v1/admin/services-types/{servicesType} — remove do catálogo.
     *
     * SÓ SE NUNCA TEVE PEDIDOS. O apagar é um SoftDelete, e a linha fica na
     * base de dados -- mas a relação `serviceType` de um pedido ignora os
     * tipos apagados, e cada pedido antigo deste tipo passava a aparecer sem
     * nome no backoffice, nos recibos e nas contas por categoria. Para tirar
     * da app um serviço que já foi pedido, desativa-se (`is_active=false`):
     * deixa de se poder pedir e o histórico fica inteiro.
     */
    public function destroy(ServicesType $servicesType): ApiSuccessResponse|ApiErrorResponse
    {
        if ($this->pedidosReais($servicesType->services())->exists()) {
            return new ApiErrorResponse(null, 'Este serviço já tem pedidos. Desativa-o em vez de o apagar.', 409);
        }

        $servicesType->delete();

        return ApiSuccessResponse::make(['id' => $servicesType->id, 'deleted' => true]);
    }

    /**
     * As contagens que a lista e a ficha mostram. `services_count` são os
     * pedidos REAIS deste tipo (os de teste não contam, como em toda a API
     * de admin) -- o mesmo critério que decide se o tipo se pode apagar.
     */
    private function contagens(): array
    {
        return [
            'vendors',
            'services' => fn ($q) => $this->pedidosReais($q),
        ];
    }

    /** As mesmas contagens da lista, para a ficha e para as respostas de editar. */
    private function comContagens(ServicesType $type): ServicesType
    {
        return $type->load('operationArea')->loadCount($this->contagens());
    }

    private function pedidosReais($pedidos)
    {
        return $pedidos->where('services.is_test', false);
    }

    /** Grava o mesmo valor nas duas línguas geridas pelo Filament (en, pt-pt). */
    private function applyName(ServicesType $type, string $value): void
    {
        $type->setTranslations('name', ['en' => $value, 'pt-pt' => $value]);
    }

    /** Lista simples de texto -> forma [{en, pt-pt}] esperada pelo Filament. */
    private function toTranslatableArray(array $items): array
    {
        return array_map(fn (string $item) => ['en' => $item, 'pt-pt' => $item], $items);
    }

    private function present(ServicesType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'operation_area_id' => $type->operation_area_id,
            'operation_area_name' => $type->operationArea?->name,
            'time' => $type->time,
            'starts_from' => $type->starts_from,
            'includes' => $type->getTranslatedIncludes(app()->getLocale()),
            'excludes' => $type->getTranslatedExcludes(app()->getLocale()),
            'vendors_count' => $type->vendors_count ?? 0,
            // Desativado = fora da app, mas com o histórico inteiro.
            'is_active' => (bool) ($type->is_active ?? true),
            // Pedidos reais deste tipo. Com algum, o tipo já não se apaga.
            'services_count' => (int) ($type->services_count ?? 0),
            'created_at' => $type->created_at?->toIso8601String(),
        ];
    }
}
