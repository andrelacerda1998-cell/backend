<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use Exception;

/** O técnico saiu para o local ("Estou a caminho"). */
class OnTheWayController extends Controller
{
    public function __invoke(Service $service)
    {
        if ($service->vendor_id !== auth()->user()->vendor?->id) {
            return new ApiErrorResponse(new Exception, 'Service not found', 404);
        }

        if ($service->status !== ServiceStatus::ACCEPTED) {
            return new ApiErrorResponse(new Exception, 'Service is not accepted', 422);
        }

        $service->on_the_way_at = now();
        $service->save();

        return new ApiSuccessResponse(['service' => $service->formatDataForVendor()]);
    }
}
