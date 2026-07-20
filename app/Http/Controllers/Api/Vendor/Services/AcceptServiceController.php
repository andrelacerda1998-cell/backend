<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\ServiceAcceptedEvent;
use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Exceptions\Api\Vendor\Service\ServiceIsNotPending;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Customer\ServiceAcceptedNotification;
use App\Services\Common\Services\AcceptService;
use Bavix\Wallet\Interfaces\Customer;

class AcceptServiceController extends Controller
{
    public function __construct(private readonly AcceptService $acceptService) {}

    public function __invoke(Service $service)
    {
        try {
            if ($service->vendor_id !== auth()->user()->vendor->id) {
                throw new ServiceNotFound;
            }

            $service = $this->acceptService->accept($service);

            return new ApiSuccessResponse(['service' => $service->formatDataForVendor()]);
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
