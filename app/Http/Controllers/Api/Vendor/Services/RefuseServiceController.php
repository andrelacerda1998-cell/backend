<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Events\Common\Services\ServiceRefusedEvent;
use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Services\Common\Services\RefuseService;

class RefuseServiceController extends Controller
{
    public function __invoke(Service $service)
    {
        try {
            if ($service->vendor_id !== auth()->user()->vendor->id) {
                throw new ServiceNotFound;
            }

            $refuseService = new RefuseService($service);
            $refuseService->refuse();

            ServiceRefusedEvent::dispatch($service->customer, $service);

            return new ApiSuccessResponse;
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
