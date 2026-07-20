<?php

namespace App\Exceptions\Api\Customer;

use Illuminate\Http\Response;

class CustomerCantRequestServices extends \Exception
{
    public function __construct(string $message = 'exceptions.services.customer_cannot_request_service')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
