<?php

namespace App\Exceptions\Api\Customer;

use Illuminate\Http\Response;

/**
 * Lançada quando um cupão não pode ser usado numa reserva (expirado/inativo ou
 * o cliente já atingiu o limite max_uses). Mantém o mesmo contrato dos restantes
 * erros de negócio (getStatus + mensagem) para o ApiErrorResponse.
 */
class VoucherNotUsable extends \Exception
{
    public function __construct(string $message = 'Este cupão não pode ser utilizado.')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_BAD_REQUEST;
    }
}
