<?php

namespace App\Http\Controllers\Api\Common\Services;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\ServicesType;

class ListServicesTypeController extends Controller
{
    public function __invoke()
    {
        $services = ServicesType::select(['id', 'name', 'operation_area_id', 'starts_from'])
            ->get()
            ->map(function ($service) {
                return [
                    'id' => $service->id,
                    'name' => $service->getTranslation('name', app()->getLocale()),
                    'operation_area_id' => $service->operation_area_id,
                    'starts_from' => $service->starts_from,
                ];
            });

        return new ApiSuccessResponse(compact('services'));
    }
}
