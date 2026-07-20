<?php

namespace App\Exceptions\Api\User;

use Illuminate\Http\Response;

class ToManyValidationsCode extends \Exception
{
    public function __construct(string $message = 'exceptions.customer.code_already_sent_recently')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }
}
