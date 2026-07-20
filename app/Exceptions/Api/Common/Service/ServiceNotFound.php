<?php

namespace App\Exceptions\Api\Common\Service;

use Illuminate\Http\Response;

class ServiceNotFound extends \Exception
{
    public function __construct(string $message = 'exceptions.services.service_not_found')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_NOT_FOUND;
    }
}
