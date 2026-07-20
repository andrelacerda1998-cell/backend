<?php

namespace App\Exceptions\Api\Vendor;

use Illuminate\Http\Response;

class VendorAccountNotValidated extends \Exception
{
    public function __construct(string $message = 'exceptions.vendor.account_not_validated')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
