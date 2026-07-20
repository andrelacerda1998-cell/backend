<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\CloseServiceEvent;
use App\Events\Vendor\Services\CloseServiceEvent as VendorCloseServiceEvent;
use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Services\Common\Services\CloseService;

class CloseServiceController extends Controller
{
    public function __invoke(Service $service)
    {
        try {
            if ($service->customer_id !== auth()->user()->id) {
                throw new ServiceNotFound;
            }

            $closeService = new CloseService($service);
            $resultStatus = $closeService->close();

            // Only fire the "closed & paid" events when the capture actually settled. When the
            // service is parked in CLOSED_PENDING_PAYMENT the vendor was NOT paid, so we must not
            // push a payout/closed event with the vendor wallet — the backoffice retry does that.
            if ($resultStatus === ServiceStatus::CLOSED) {
                CloseServiceEvent::dispatch($service);
                $serviceDataForVendor = $service->formatDataForVendor();
                VendorCloseServiceEvent::dispatch($service->vendor, [
                    ...$serviceDataForVendor,
                    'vendor' => [
                        ...$serviceDataForVendor['vendor'],
                        'wallet' => $service->vendor->wallet,
                    ],
                ]);
            }

            $serviceDataForCustomer = $service->formatDataForCustomer();
            return new ApiSuccessResponse(['service' => $serviceDataForCustomer]);
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
