<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Models\Vendor;

class ListPendingServicesController extends Controller
{
    public function __invoke()
    {
        $vendor = auth()->user()->vendor;
        $openServices = $vendor->openServices()->get();

        $transformedServices = $openServices->transform(fn (Service $service) => $this->transformService($service, $vendor));

        return new ApiSuccessResponse(['services' => $transformedServices]);
    }

    private function transformService(Service $service, Vendor $vendor): array
    {
        $distance = $vendor->calculateDistance($service->address);
        $serviceType = $service->serviceType->name;
        $hourlyRate = $vendor->price_rate;

        return [
            'id' => $service->id,
            'status' => $service->status,
            'distance' => $distance,
            'service_type' => $serviceType,
            'value' => $hourlyRate,
            'created_at' => $service->created_at,
        ];
    }
}
