<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;

class GetServiceDetailsController extends Controller
{
    public function __invoke(Service $service)
    {
        try {
            if ($service->vendor_id !== auth()->user()->vendor->id) {
                throw new ServiceNotFound;
            }

            $service = $service->load('customer', 'serviceType', 'vendor');

            $data = [
                'status' => $service->status,
                'distance' => $service->distance,
                'customer_notes' => $service->customer_notes,
                'vendor_notes' => $service->vendor_notes,
                'amount' => $service->amount,
                'customer' => $service->customer->only('name', 'phone', 'email'),
                'vendor' => [
                    'user' => $service->vendor->user->only('name', 'phone', 'email'),
                    'price_rate' => $service->vendor->price_rate,
                ],
                'service_type' => [
                    'id' => $service->serviceType->id,
                    'name' => $service->serviceType->name,
                    'time' => $service->serviceType->time,
                    'operation_area' => $service->serviceType->operationArea->only('id', 'name'),
                ],
                'rating_by_vendor' => $service->rating_by_vendor,
                'server_time' => now()->toIso8601String(),
                'created_timestamp' => $service->created_at->timestamp,
                'updated_timestamp' => $service->updated_at->timestamp,
            ];

            return new ApiSuccessResponse([
                'service' => $data,
            ]);
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
