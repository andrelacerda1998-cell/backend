<?php

namespace App\Exceptions\Api\Vendor;

use Illuminate\Http\Response;

class VendorAccountWorkspaceRequired extends \Exception
{
    public function __construct(string $message = 'exceptions.vendor.account_workspace_required')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
