<?php

namespace App\Exceptions\Api\Common\Service;

use Illuminate\Http\Response;

class ServiceNotPossibleToCancel extends \Exception
{
    public function __construct(string $message = 'exceptions.services.service_not_possible_to_cancel')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
