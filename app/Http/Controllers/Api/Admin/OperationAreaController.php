<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreOperationAreaRequest;
use App\Http\Requests\Api\Admin\UpdateOperationAreaRequest;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\OperationArea;
use Illuminate\Http\Request;

/**
 * Categorias — equivalente ao Filament OperationAreaResource. Mesma fatia
 * "Lista + criar/editar, sem apagar" do ServicesTypeController (decisão do
 * utilizador, 2026-07-29). Ficam de fora:
 *
 * - `image` (upload) -- idem ServicesTypeController.
 * - `documents` (certificações exigidas) -- o Select do Filament já tem um bug
 *   pré-existente (`Document::where('required', 'false')`, comparação de
 *   string em vez de boolean, nunca filtra nada) e a gestão de certificações
 *   é um fluxo à parte; fica para uma fatia futura.
 *
 * `name` é traduzível no Filament (EN + PT-PT); aqui é um único campo,
 * gravado nas duas línguas (ver applyName).
 */
class OperationAreaController extends Controller
{
    public function index(Request $request): ApiSuccessResponse
    {
        $perPage = min((int) $request->integer('per_page', 100), 200);

        $query = OperationArea::query()->withCount(['vendors', 'servicesType'])->orderBy('id');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where('name', 'like', "%{$search}%");
        }

        $areas = $query->paginate($perPage);

        return ApiSuccessResponse::make([
            'items' => collect($areas->items())->map($this->present(...))->all(),
            'meta' => [
                'current_page' => $areas->currentPage(),
                'last_page' => $areas->lastPage(),
                'per_page' => $areas->perPage(),
                'total' => $areas->total(),
            ],
        ]);
    }

    public function store(StoreOperationAreaRequest $request): ApiSuccessResponse
    {
        $area = new OperationArea();
        $this->applyName($area, $request->validated('name'));
        $area->save();

        return ApiSuccessResponse::make($this->present($area->fresh()), statusCode: 201);
    }

    public function update(UpdateOperationAreaRequest $request, OperationArea $operationArea): ApiSuccessResponse
    {
        $data = $request->validated();

        if (array_key_exists('name', $data)) {
            $this->applyName($operationArea, $data['name']);
        }
        $operationArea->save();

        return ApiSuccessResponse::make($this->present($operationArea->fresh()));
    }

    /** Grava o mesmo valor nas duas línguas geridas pelo Filament (en, pt-pt). */
    private function applyName(OperationArea $area, string $value): void
    {
        $area->setTranslations('name', ['en' => $value, 'pt-pt' => $value]);
    }

    private function present(OperationArea $area): array
    {
        return [
            'id' => $area->id,
            'name' => $area->name,
            'vendors_count' => $area->vendors_count ?? 0,
            'services_types_count' => $area->services_type_count ?? 0,
            'created_at' => $area->created_at?->toIso8601String(),
        ];
    }
}
