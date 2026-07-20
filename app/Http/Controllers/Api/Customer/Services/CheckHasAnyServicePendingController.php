<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;

class CheckHasAnyServicePendingController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();

        // $service = $user->vendor->services()->whereIn('status', [ServiceStatus::ACCEPTED])->get()->first();

        // return new ApiSuccessResponse(compact('service'));

        $service = $user->services()->whereIn('status', [ServiceStatus::PENDING])->where('payment_status', [PaymentStatus::PAID])->get()->first();

        if (!$service) {
            return new ApiSuccessResponse(compact('service'));
        }

        $service = [
            'id' => $service->id,
            'amount' => $service->amount,
            'distance' => $service->distance,
            'amount_for_vendor' => $service->amount_for_vendor,
            'status' => $service->status,
            'vendor' => [
                'username' => $service->vendor->username,
                'user' => [
                    'name' => $service->vendor->user->name,
                    'avatar' => $service->vendor->user->avatar,
                ],
            ],
            'customer' => $service->customer->only(['name', 'address', 'avatar']),
            'address' => $service->address ? [
                'address_name' => '',
                'name' => $service->address['name'],
                'additional_info' => $service->address['additional_info'],
            ] : null,
            'service_area' => $service->serviceType->operationArea->only(['name']),
            'service_type' => $service->serviceType->only(['time', 'name']),
            'updated_at' => $service->updated_at,
            'server_time' => now()->toIso8601String()
        ];

        return new ApiSuccessResponse(compact('service'));
    }
}
