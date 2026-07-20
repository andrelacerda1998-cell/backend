<?php

namespace App\Http\Controllers\Api\Customer\PaymentMethods;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use RwInteractive\PayshopSdk\Models\PaymentMethod;

class DeletePaymentMethodController extends Controller
{
    public function __invoke(PaymentMethod $paymentMethod)
    {
        try {
            $customer = auth('api')->user();

            if ($customer->id !== $paymentMethod->user_id) {
                throw new \Exception('Invalid payment method');
            }
            $paymentMethod->delete();
            return ApiSuccessResponse::make();
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }

    }
}
