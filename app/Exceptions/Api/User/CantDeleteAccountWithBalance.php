<?php

namespace App\Exceptions\Api\User;

use Illuminate\Http\Response;

class CantDeleteAccountWithBalance extends \Exception
{
    public function __construct(string $message = 'exceptions.user.cantDeleteAccountWithBalance')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }
}
