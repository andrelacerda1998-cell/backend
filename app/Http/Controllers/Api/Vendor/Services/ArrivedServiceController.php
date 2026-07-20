<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\ArrivedServiceEvent;
use App\Events\Customer\Services\ServiceAcceptedEvent;
use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Exceptions\Api\Vendor\Service\ServiceIsNotAccepted;
use App\Exceptions\Api\Vendor\Service\ServiceIsNotPending;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Customer\ServiceAcceptedNotification;
use App\Notifications\Customer\ServiceVendorArrivedNotification;
use Bavix\Wallet\Interfaces\Customer;

class ArrivedServiceController extends Controller
{
    public function __invoke(Service $service)
    {
        try {
            if ($service->vendor_id !== auth()->user()->vendor->id) {
                throw new ServiceNotFound;
            }

            if ($service->status != ServiceStatus::ACCEPTED) {
                throw new ServiceIsNotAccepted;
            }

            $service->status = ServiceStatus::ARRIVED;
            $service->save();

            $this->notifyCustomer($service->formatDataForCustomer(), $service);

            return new ApiSuccessResponse(['service' => $service->formatDataForVendor()]);
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }

    private function notifyCustomer(array $serviceData, Service $service)
    {
        ArrivedServiceEvent::dispatch($serviceData);
        $service->customer->notify(new ServiceVendorArrivedNotification($service));
    }
}
