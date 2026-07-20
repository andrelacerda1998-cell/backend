<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Events\Vendor\Services\ServiceCanceledEvent;
use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Services\Common\Services\CancelService;

class CancelController extends Controller
{
    public function __invoke(Service $service)
    {
        try {
            if ($service->vendor_id !== auth()->user()->vendor->id) {
                throw new ServiceNotFound;
            }

            $cancelService = new CancelService($service);
            $cancelService->vendorCancelService();

            ServiceCanceledEvent::dispatch($service->vendor->user->id, $service);

            return new ApiSuccessResponse;
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
