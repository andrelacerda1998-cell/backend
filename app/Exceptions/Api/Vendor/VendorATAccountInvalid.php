<?php

namespace App\Exceptions\Api\Vendor;

use Illuminate\Http\Response;

class VendorATAccountInvalid extends \Exception
{
    public function __construct(string $message = 'Os dados de conta da AT estão invalidos')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
