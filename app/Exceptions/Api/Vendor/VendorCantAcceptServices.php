<?php

namespace App\Exceptions\Api\Vendor;

use Illuminate\Http\Response;

class VendorCantAcceptServices extends \Exception
{
    public function __construct(string $message = 'exceptions.vendor.vendor_cannot_accept_service')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
