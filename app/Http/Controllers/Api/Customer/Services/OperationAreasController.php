<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\Services\OperationAreasRequest;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use Illuminate\Support\Str;

class OperationAreasController extends Controller
{
    public function index()
    {
        // Ordem e visibilidade vêm do backoffice; antes vinham todas e a app
        // ordenava alfabeticamente por sua conta.
        $operation_areas = OperationArea::select(['id', 'name', 'sort_order'])
            ->active()
            ->ordered()
            ->get();
        $lang = app()->getLocale();

        $operation_areas = $operation_areas->transform(function (OperationArea $operation_area) use ($lang) {
            $name = $operation_area->getTranslation('name', $lang);
            $image = $operation_area->image_url;

            if ($image && ! Str::startsWith($image, ['http://', 'https://'])) {
                $image = 'http://localhost'.Str::start($image, '/');
            }

            return [
                'id' => $operation_area->id,
                'name' => $name,
                'image' => $image,
            ];
        });

        return new ApiSuccessResponse(compact('operation_areas'));
    }

    /**
     * Destaques da Home ("serviços populares").
     *
     * A lista é definida no backoffice (is_popular + popular_order). Sem nada
     * marcado devolve vazio — a app esconde a secção em vez de inventar uma
     * seleção, que era o que acontecia quando ia buscar os primeiros serviços
     * das primeiras áreas.
     */
    public function popular()
    {
        $lang = app()->getLocale();

        $services = ServicesType::with('operationArea')
            ->active()
            ->popular()
            ->limit(12)
            ->get()
            ->map(function (ServicesType $service) use ($lang) {
                return [
                    'id' => $service->id,
                    'name' => $service->getTranslation('name', $lang),
                    'time' => $service->time,
                    'image' => $service->image_url,
                    'operation_area' => $service->operationArea ? [
                        'id' => $service->operationArea->id,
                        'name' => $service->operationArea->getTranslation('name', $lang),
                    ] : null,
                ];
            });

        return new ApiSuccessResponse(['services' => $services]);
    }

    public function servicesTypes(OperationArea $operationArea)
    {
        $services = $operationArea->servicesType()
            ->select(['id', 'name', 'includes', 'excludes', 'time', 'starts_from', 'sort_order'])
            ->active()
            ->ordered()
            ->get();
        $lang = app()->getLocale();

        $services = $services->transform(function ($service) use ($lang, $operationArea) {
            $name = $service->getTranslation('name', $lang);

            return [
                'id' => $service->id,
                'name' => $name,
                'includes' => $service->getTranslatedIncludes(),
                'excludes' => $service->getTranslatedExcludes(),
                'starts_from' => $service->starts_from,
                'time' => $service->time,
                'image' => $service->getFirstTemporaryUrl(now()->addHour(), 'image'),
                'operation_area' => [
                    'id' => $operationArea->id,
                    'name' => $operationArea->getTranslation('name', $lang),
                    'image' => $operationArea->getFirstTemporaryUrl(now()->addHour(), 'image'),
                ],
            ];
        });

        return new ApiSuccessResponse(compact('services'));
    }

    public function search(OperationAreasRequest $request)
    {
        $operationAreaIds = $request->get('operation_areas', []);
        $lang = app()->getLocale();

        if (empty($operationAreaIds)) {
            $services_types = ServicesType::where('operation_area_id', '!=', null)->get();
            $services_types = $services_types->transform(function ($service) use ($lang) {
                return [
                    'id' => $service->id,
                    'name' => $service->getTranslation('name', $lang),
                    'includes' => $service->getTranslatedIncludes(),
                    'excludes' => $service->getTranslatedExcludes(),
                    'starts_from' => $service->starts_from,
                    'time' => $service->time,
                    'image' => $service->getFirstTemporaryUrl(now()->addHour(), 'image'),
                    'operation_area' => [
                        'id' => $service?->operationArea?->id,
                        'name' => $service?->operationArea?->getTranslation('name', $lang),
                        'image' => $service?->operationArea?->getFirstMediaUrl('image'),
                    ],
                ];
            });

            return new ApiSuccessResponse(['services_types' => $services_types]);
        }

        $operationAreas = OperationArea::with([
            'media',
            'servicesType' => function ($query) {
                $query->select(['id', 'name', 'includes', 'excludes', 'time', 'starts_from', 'operation_area_id']);
            },
            'servicesType.media',
        ])
            ->whereIn('id', $operationAreaIds)
            ->select(['id', 'name'])
            ->get();

        $servicesTypes = collect();
        foreach ($operationAreas as $operationArea) {
            $servicesTypes = $servicesTypes->concat($operationArea->servicesType->map(function ($service) use ($lang, $operationArea) {
                return [
                    'id' => $service->id,
                    'name' => $service->getTranslation('name', $lang),
                    'includes' => $service->getTranslatedIncludes(),
                    'excludes' => $service->getTranslatedExcludes(),
                    'starts_from' => $service->starts_from,
                    'time' => $service->time,
                    'image' => $service->image_url,
                    'operation_area' => [
                        'id' => $operationArea->id,
                        'name' => $operationArea->getTranslation('name', $lang),
                        'image' => $operationArea->image_url,
                    ],
                ];
            }));
        }

        return new ApiSuccessResponse(['services_types' => $servicesTypes]);
    }
}
