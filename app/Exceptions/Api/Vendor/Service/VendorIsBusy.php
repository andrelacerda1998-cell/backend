<?php

namespace App\Exceptions\Api\Vendor\Service;

use Illuminate\Http\Response;

/**
 * O profissional já está comprometido com um trabalho agora (ver
 * Vendor::ocupadoAgora) e tentou aceitar outro imediato.
 */
class VendorIsBusy extends \Exception
{
    public function __construct(string $message = 'exceptions.vendor.service.vendor_is_busy')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_CONFLICT;
    }
}
