<?php

namespace App\Exceptions\Api\Vendor;

use Illuminate\Http\Response;

class VendorAtAccountNeedAttention extends \Exception
{
    public function __construct(string $message = 'exceptions.vendor.at_Account_need_attention')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
