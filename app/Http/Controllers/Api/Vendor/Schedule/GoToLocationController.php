<?php

namespace App\Http\Controllers\Api\Vendor\Schedule;

use App\Enums\Services\ServiceStatus;
use App\Events\Customer\Services\VendorHeadingToLocationEvent;
use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Exceptions\Api\Vendor\Service\ServiceIsNotScheduled;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Notifications\Customer\ServiceVendorOnTheWayNotification;

class GoToLocationController extends Controller
{
    public function __invoke(Service $service)
    {
        try {
            // IDOR: um vendor só pode operar os seus próprios serviços (mesmo guard do AcceptServiceController).
            if ($service->vendor_id !== auth()->user()->vendor->id) {
                throw new ServiceNotFound;
            }

            if ($service->status != ServiceStatus::SCHEDULED) {
                throw new ServiceIsNotScheduled;
            }

            $service->status = ServiceStatus::ACCEPTED;
            $service->save();

            $this->notifyCustomer($service->formatDataForCustomer(), $service);

            return new ApiSuccessResponse(['service' => $service->formatDataForVendor()]);
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }

    private function notifyCustomer(array $serviceData, Service $service): void
    {
        VendorHeadingToLocationEvent::dispatch($service->customer->id, $serviceData);
        $service->customer->notify(new ServiceVendorOnTheWayNotification($service));
    }
}
