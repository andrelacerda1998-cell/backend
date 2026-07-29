<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ServiceRateRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;

class CustomerRateServiceController extends Controller
{
    public function __invoke(ServiceRateRequest $request, Service $service)
    {
        if ($service->customer_id !== auth()->user()->id) {
            throw new ServiceNotFound;
        }
        if ($service->rating_by_customer !== null) {
            return new ApiErrorResponse(null, 'You have already rated this service');
        }
        $service->update([
            'rating_by_customer' => $request->get('rate'),
            'rating_comment_by_customer' => $request->get('comment'),
        ]);

        return new ApiSuccessResponse(compact('service'));
    }
}
