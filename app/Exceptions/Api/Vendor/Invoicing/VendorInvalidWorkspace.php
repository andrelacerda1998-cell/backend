<?php

namespace App\Exceptions\Api\Vendor\Invoicing;

use Illuminate\Http\Response;

class VendorInvalidWorkspace extends \Exception
{
    public function __construct(string $message = 'exceptions.vendor.vendor_cannot_invalid_workspace')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_INTERNAL_SERVER_ERROR;
    }
}
