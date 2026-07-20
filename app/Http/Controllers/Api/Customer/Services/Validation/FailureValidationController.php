<?php

namespace App\Http\Controllers\Api\Customer\Services\Validation;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Service;
use RwInteractive\PayshopSdk\Api\Payments\PaymentOrder;

class FailureValidationController extends Controller
{
    public function __invoke(Service $service)
    {
        if ($service->payment_status == PaymentStatus::PAID) {
            abort(400, 'Service already paid');
        }

        // Leave an archived service untouched
        if ($service->status === ServiceStatus::ARCHIVED) {
            abort(400, 'Service archived');
        }

        $paymentOrderService = PaymentOrder::make();
        $paymentDetails = $paymentOrderService->details($service->paymentOrder);

        if ($paymentDetails['order']['status'] === 'PAID'){
            abort(400, 'Payment is complete');
        }


        $service->payment_status = PaymentStatus::CANCELED;
        if ($service->status === ServiceStatus::PENDING_3DS) {
            $service->status = ServiceStatus::CANCELED;
        }
        $service->save();

        return redirect()->away('piquet.customer:://(app)/(bottom-sheets)/failed');
    }
}
