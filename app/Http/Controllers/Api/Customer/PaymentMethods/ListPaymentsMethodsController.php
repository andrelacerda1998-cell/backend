<?php

namespace App\Http\Controllers\Api\Customer\PaymentMethods;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use RwInteractive\PayshopSdk\Enums\PaymentMethods\PaymentMethodType;

class ListPaymentsMethodsController extends Controller
{
    public function __invoke()
    {
        $user = auth('api')->user();

        $data = $user->paymentMethods->reduce(function ($carry, $paymentMethod) use ($user) {
            if ($paymentMethod->type !== PaymentMethodType::MBWAY) {
                $paymentMethod->isDefault = $paymentMethod->id === $user->default_payment_method_id;
                $carry->push($paymentMethod);
            }
            return $carry;
        }, collect());

        return ApiSuccessResponse::make($data);
    }
}
