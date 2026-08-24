<?php

namespace App\Exceptions;

use Exception;

/**
 * Erro de fluxo no checkout da seleção de profissional.
 *
 * Carrega o estado HTTP via `getStatus()`, que é o que o `ApiErrorResponse`
 * procura — uma `Exception` normal com código sai como 500 e o cliente fica
 * sem saber que foi ele a chegar tarde.
 */
class MatchingCheckoutException extends Exception
{
    public function __construct(string $message, private readonly int $status = 422)
    {
        parent::__construct($message, $status);
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
