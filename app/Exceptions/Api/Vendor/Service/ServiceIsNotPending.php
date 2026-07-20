<?php

namespace App\Exceptions\Api\Vendor\Service;

use Illuminate\Http\Response;

class ServiceIsNotPending extends \Exception
{
    public function __construct(string $message = 'exceptions.vendor.service.service_is_not_pending')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
