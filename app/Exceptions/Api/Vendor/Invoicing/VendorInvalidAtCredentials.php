<?php

namespace App\Exceptions\Api\Vendor\Invoicing;

use Illuminate\Http\Response;

class VendorInvalidAtCredentials extends \Exception
{
    public function __construct(string $message = 'exceptions.vendor.vendor_wrong_credentials')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }
}
