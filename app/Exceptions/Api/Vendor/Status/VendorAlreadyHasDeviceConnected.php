<?php

namespace App\Exceptions\Api\Vendor\Status;

use Illuminate\Http\Response;

class VendorAlreadyHasDeviceConnected extends \Exception
{
    public function __construct(string $message = 'exceptions.vendor.already_has_device_connected')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
