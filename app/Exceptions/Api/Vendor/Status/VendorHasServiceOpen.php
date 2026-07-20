<?php

namespace App\Exceptions\Api\Vendor\Status;

use Illuminate\Http\Response;

class VendorHasServiceOpen extends \Exception
{
    public function __construct(string $message = 'exceptions.vendor.has_service_open')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
