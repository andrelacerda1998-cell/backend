<?php

namespace App\Http\Controllers\Api\Common;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;

class PaymentMethodsController extends Controller
{
    public function __invoke(): ApiSuccessResponse
    {
        $methods = [
            [
                'id' => 'credit_card',
                'label' => 'Credit Card',
                'enabled' => (bool) config('payment_methods.credit_card.enabled'),
            ],
            [
                'id' => 'mbway',
                'label' => 'MBWay',
                'enabled' => (bool) config('payment_methods.mbway.enabled'),
            ],
        ];

        return ApiSuccessResponse::make(['payment_methods' => $methods]);
    }
}
