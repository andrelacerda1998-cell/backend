<?php

namespace App\Exceptions\Api\Customer;

use Illuminate\Http\Response;

class PaymentNotComplete extends \Exception
{
    public function __construct(string $message = 'exceptions.services.payment_not_complete')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }
}
