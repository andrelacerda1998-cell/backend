<?php

namespace App\Http\Controllers\Api\Customer\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\Vendor;
use App\Services\Customer\Services\ScheduleVendorSearchService;

class VendorAvailabilityController extends Controller
{
    public function __invoke(Vendor $vendor, ScheduleVendorSearchService $searchService)
    {
        try {
            $serviceId = request()->input('service_id');
            $serviceTypeId = request()->input('service_type_id');
            $serviceDurationMinutes = null;
            if ($serviceId) {
                $service = Service::query()->findOrFail($serviceId);
                $serviceDurationMinutes = (int) $service->serviceType?->time;
            } elseif ($serviceTypeId) {
                $serviceType = ServicesType::query()->findOrFail($serviceTypeId);
                $serviceDurationMinutes = (int) $serviceType->time;
            } else {
                return new ApiErrorResponse(new \Exception('service_id or service_type_id is required'), 'service_id or service_type_id is required', 400);
            }

            $availableSlots = $searchService->getAvailableSlots($vendor, $serviceDurationMinutes);

            return new ApiSuccessResponse([
                'vendor_id' => $vendor->id,
                'available_slots' => $availableSlots,
            ]);
        } catch (\Exception $exception) {
            return new ApiErrorResponse($exception);
        }
    }
}
