<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ServiceRateRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;

class VendorRateServiceController extends Controller
{
    public function __invoke(ServiceRateRequest $request, Service $service)
    {
        if ($service->vendor_id !== auth()->user()->vendor->id) {
            throw new ServiceNotFound;
        }
        if ($service->rating_by_vendor !== null) {
            return new ApiErrorResponse(null, 'You have already rated this service');
        }
        $service->update(['rating_by_vendor' => $request->get('rate')]);

        return new ApiSuccessResponse(compact('service'));
    }
}
