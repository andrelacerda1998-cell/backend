<?php

namespace App\Exceptions\Api\Customer;

use Illuminate\Http\Response;

class CustomerDontHaveBalance extends \Exception
{
    public function __construct(string $message = 'exceptions.services.customer_dont_have_balance')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }
}
