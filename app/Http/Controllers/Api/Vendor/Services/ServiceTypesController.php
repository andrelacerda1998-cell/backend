<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\Services\ServicesTypesRequest;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Vendor;

class ServiceTypesController extends Controller
{
    public function index()
    {
        $vendor = auth()->user()->vendor;

        $servicesTypesAvailable = $this->getServicesTypes($vendor);
        $currentServicesTypes = $this->getCurrentServicesTypes($vendor);
        $filteredServicesTypesAvailable = $this->filterAvailableServicesTypes($servicesTypesAvailable, $currentServicesTypes);

        return new ApiSuccessResponse(compact('currentServicesTypes', 'filteredServicesTypesAvailable'));
    }

    /**
     * Extract function to get available services types
     */
    private function getServicesTypes($vendor)
    {
        return ServicesType::all()->map(function (ServicesType $servicesType) use ($vendor) {
            return [
                'id' => $servicesType->id,
                'name' => $servicesType->name,
                'suggested_price' => $servicesType->suggested_price,
                'operation_area_id' => $servicesType->operation_area_id,
            ];
        });
    }

    /**
     * Extract function to get current services types
     */
    private function getCurrentServicesTypes($vendor)
    {
        return $vendor->servicesTypes->map(fn(ServicesType $servicesType) => [
            'id' => $servicesType->id,
            'name' => $servicesType->name,
            'suggested_price' => $servicesType->suggested_price,
            'current_price' => $vendor->price_rate,
            'operation_area_id' => $servicesType->operation_area_id,
        ]);
    }

    /**
     * Extract function to filter available services types
     */
    private function filterAvailableServicesTypes($available, $current)
    {
        return $available->reject(function ($servicesType) use ($current) {
            return in_array($servicesType['id'], $current->pluck('id')->toArray());
        })->values();
    }

    public function store(ServicesTypesRequest $request)
    {
        $vendor = auth()->user()->vendor;

        $services = collect($request->get('services'))->map(function ($service) {
            if (isset($service['id'])) {
                $service['services_type_id'] = $service['id'];
                unset($service['id']);
            }
            return $service;
        })->toArray();

        $vendor->setServices($services);

        return new ApiSuccessResponse;
    }
}
