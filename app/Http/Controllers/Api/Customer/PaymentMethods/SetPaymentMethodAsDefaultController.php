<?php

namespace App\Http\Controllers\Api\Customer\PaymentMethods;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use Exception;
use RwInteractive\PayshopSdk\Models\PaymentMethod;

class SetPaymentMethodAsDefaultController extends Controller
{
    public function __invoke(PaymentMethod $paymentMethod)
    {
        try {
            $customer = auth('api')->user();

            if ($customer->id !== $paymentMethod->user_id) {
                // 404 e nao 500 — e tambem nao 403: o cartao de outra
                // pessoa nao existe do ponto de vista de quem pergunta, e
                // confirmar que existe ja e dizer alguma coisa. Sem codigo,
                // isto caia no 500 por omissao e a app mostrava "Something
                // went wrong" a quem tocou num id que nao e dele.
                throw new Exception('Payment method not found', 404);
            }

            $customer->default_payment_method_id = $paymentMethod->id;
            $customer->save();

            return ApiSuccessResponse::make();
        } catch (Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
