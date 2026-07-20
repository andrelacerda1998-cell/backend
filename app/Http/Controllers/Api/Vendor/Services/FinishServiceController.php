<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Events\Common\Services\FinishServiceEvent;
use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Customer\ServiceFinishedNotification;
use App\Services\Vendor\Services\FinishService;

class FinishServiceController extends Controller
{
    public function __invoke(Service $service)
    {
        try {
            $user = auth()->user();

            if ($service->vendor_id !== $user->vendor->id) {
                throw new ServiceNotFound;
            }

            $finishService = new FinishService($service);
            $finishService->finish();

            $this->notifyCustomer($service->formatDataForCustomer(), $service);

            return new ApiSuccessResponse(['service' => $service->formatDataForVendor()]);
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }

    public function notifyCustomer(array $serviceData, Service $service)
    {
        FinishServiceEvent::dispatch($serviceData);
        $service->customer->notify(new ServiceFinishedNotification($service));
    }
}
