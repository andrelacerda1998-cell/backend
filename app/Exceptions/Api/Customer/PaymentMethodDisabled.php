<?php

namespace App\Exceptions\Api\Customer;

use Illuminate\Http\Response;

class PaymentMethodDisabled extends \Exception
{
    public function __construct(string $message = 'exceptions.payment_methods.disabled')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
