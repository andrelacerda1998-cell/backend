<?php

namespace App\Http\Controllers\Api\Vendor\Location;

use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\UpdateLocationEvent;
use App\Events\Vendor\Services\UpdateLocationEvent as VendorUpdateLocationEvent;
use App\Exceptions\Api\Vendor\Status\VendorAlreadyHasDeviceConnected;
use App\Exceptions\Api\Vendor\VendorCantAcceptServices;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\StoreLocationRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;

class UpdateLocationController extends Controller
{
    public function __invoke(StoreLocationRequest $request)
    {
        try {
            $vendor = auth('api')->user()->vendor;

            $currentLocation = $vendor->currentLocation;
            if ($currentLocation) {
                if ($currentLocation->updated_at->diffInMinutes(now()) < 10) {
                    if ($vendor->currentLocation->device_id !== $request->get('device_id')) {
                        throw new VendorAlreadyHasDeviceConnected();
                    }
                }
            }

            $vendor->currentLocation()->updateOrCreate([], $request->only('latitude', 'longitude', 'device_id'));
            $vendor->currentLocation()->touch();
            $vendor->with('servicesTypes');
            $vendor->searchable();

            $service = $vendor->services()
                ->whereIn('status', [ServiceStatus::FINISHED, ServiceStatus::ACCEPTED])
                ->whereDoesntHave('schedule')
                ->get()
                ->first();

            if ($service) {
                UpdateLocationEvent::dispatch($service->formatDataForCustomer());
                VendorUpdateLocationEvent::dispatch($service->formatDataForVendor(), $vendor->user->id);
            }
            $vendor->refresh();
            return new ApiSuccessResponse([
                'current_location' => $vendor->currentLocation->only('latitude', 'longitude'),
            ]);
        } catch (VendorCantAcceptServices|VendorAlreadyHasDeviceConnected $exception) {
            return new ApiErrorResponse($exception);
        }

    }
}
