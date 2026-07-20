<?php

namespace App\Exceptions\Api\Customer;

use Illuminate\Http\Response;

class PaymentRefused extends \Exception
{
    public function __construct(string $message = 'exceptions.services.payment_refused')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_PAYMENT_REQUIRED;
    }
}
