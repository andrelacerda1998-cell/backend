<?php

namespace App\Exceptions\Api\Common\PaymentMethods;

use Illuminate\Http\Response;

class CreditCardInvalidData extends \Exception
{
    public function __construct(string $message = 'exceptions.payment_methods.credit_card_invalid_data')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
