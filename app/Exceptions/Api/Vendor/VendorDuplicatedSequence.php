<?php

namespace App\Exceptions\Api\Vendor;

use Illuminate\Http\Response;

class VendorDuplicatedSequence extends \Exception
{
    public function __construct(string $message = 'Duplicated sequence')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
