<?php

namespace App\Exceptions\Api\Common;

use Illuminate\Http\Response;

class WrongAppVersion extends \Exception
{
    public function __construct(string $message = 'exceptions.common.wrong_app_version')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_UPGRADE_REQUIRED;
    }
}
