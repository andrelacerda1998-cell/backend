<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;

class CheckHasAnyServiceOpenController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();

        $service = $user->services()
            ->whereIn('status', [ServiceStatus::FINISHED, ServiceStatus::ACCEPTED, ServiceStatus::ARRIVED])
            //->whereDoesntHave('schedule')
            ->get()
            ->first();
        if (!$service) {
            return new ApiSuccessResponse(compact('service'));
        }
        $service->load('vendor');

        return new ApiSuccessResponse(['service' => $service->formatDataForCustomer()]);
    }
}
