<?php

namespace App\Exceptions\Api\Common\Service;

use Illuminate\Http\Response;

class ServiceAlreadyCanceled extends \Exception
{
    public function __construct(string $message = 'exceptions.services.service_already_canceled')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
