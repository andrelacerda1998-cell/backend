<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\ServiceCanceledEvent;
use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Notifications\Customer\ServiceCanceledByVendorNotification;
use App\Services\Common\Services\CancelService;

class CancelServiceController extends Controller
{
    public function __invoke(Service $service)
    {
        try {
            if ($service->vendor_id !== auth()->user()->vendor?->id) {
              throw new ServiceNotFound;
            }

            $cancelService = new CancelService($service);
            if ($service->status === ServiceStatus::PENDING || $service->status === ServiceStatus::SCHEDULED) {
                $cancelService->vendorCancelService();
            } elseif ($service->status === ServiceStatus::ACCEPTED || $service->status === ServiceStatus::ARRIVED) {
                $cancelService->cancelOpenService();
            }

            $service->refresh();

            $serviceDetails = $service->only(['id']);

            ServiceCanceledEvent::dispatch($serviceDetails);

            // Notificar SOMENTE o customer do serviço, e só se o cancelamento efetivou
            if ($service->status === ServiceStatus::CANCELED) {
                $customer = $service->customerUser; // sem filtro whereDoesntHave -> nunca null por causa do vendor
                if ($customer && ! $customer->trashed() && $customer->devices()->exists()) {
                    try {
                        $customer->notify(new ServiceCanceledByVendorNotification($service));
                    } catch (\Throwable $e) {
                        report($e); // falha de push NUNCA quebra o cancelamento
                    }
                }
            }

            return new ApiSuccessResponse;
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
