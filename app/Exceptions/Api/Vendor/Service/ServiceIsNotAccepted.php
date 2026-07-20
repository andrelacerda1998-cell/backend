<?php

namespace App\Exceptions\Api\Vendor\Service;

use Illuminate\Http\Response;

class ServiceIsNotAccepted extends \Exception
{
    public function __construct(string $message = 'exceptions.vendor.service.service_is_not_accepted')
    {
        parent::__construct($message);
}

    public function getStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
