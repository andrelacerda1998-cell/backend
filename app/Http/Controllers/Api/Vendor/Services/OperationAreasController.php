<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\Services\OperationAreasRequest;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\OperationArea;
use Symfony\Component\HttpFoundation\Response;

class OperationAreasController extends Controller
{
    public function index()
    {
        $operation_areas = OperationArea::select(['id', 'name'])->get();
        $lang = app()->getLocale();

        $operation_areas = OperationArea::with(['servicesType'])
            ->get()
            ->map(function (OperationArea $operation_area) use ($lang) {
                $name = $operation_area->getTranslation('name', $lang);

                return [
                    'id' => $operation_area->id,
                    'name' => $name,
                    'services_types' => $operation_area->servicesType->map(function ($serviceType) use ($lang) {
                        return [
                            'id' => $serviceType->id,
                            'name' => $serviceType->getTranslation('name', $lang),
                            'suggested_price' => $serviceType->suggested_price,
                            'current_price' => optional($serviceType->pivot)->price_rate,
                        ];
                    })->values(),
                ];
            });

        $vendor = auth('api')?->user()?->vendor;

        if ($vendor) {
            $lang = app()->getLocale();

            $operation_areas = OperationArea::with(['servicesType'])
                ->get()
                ->map(function (OperationArea $operation_area) use ($lang, $vendor) {
                    $name = $operation_area->getTranslation('name', $lang);

                    return [
                        'id' => $operation_area->id,
                        'name' => $name,
                        'services_types' => $operation_area->servicesType->map(function ($serviceType) use ($lang, $vendor) {
                            return [
                                'id' => $serviceType->id,
                                'name' => $serviceType->getTranslation('name', $lang),
                                'suggested_price' => $serviceType->suggested_price,
                                'current_price' => optional($serviceType->pivot)->price_rate,
                            ];
                        })->values(),
                        'services_types_subscribed' =>
                            $vendor->servicesTypes
                                ->where('operation_area_id', $operation_area->id)
                                ->pluck('id')
                                ->values()
                    ];
                });

            return new ApiSuccessResponse(compact( 'operation_areas'));
        };

        return new ApiSuccessResponse(compact('operation_areas'));
    }

    public function store(OperationAreasRequest $request)
    {
        // firstOrFail (mesmo padrão do ScheduleController) — evita "call on null" (500) se o
        // vendor não existir; no fluxo normal o vendor já foi criado no signup.
        $vendor = auth('api')->user()->vendor()->firstOrFail();

        $vendor->operationAreas()->sync($request->get('operation_areas'));

        return new ApiSuccessResponse(statusCode: Response::HTTP_CREATED);
    }
}
