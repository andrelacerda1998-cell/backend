<?php

namespace App\Exceptions\Api\Customer;

use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class CustomerCantRequestServices extends \Exception
{
    public function __construct(string $message = 'exceptions.services.customer_cannot_request_service')
    {
        parent::__construct($message);
    }

    /**
     * A mesma recusa, mas a dizer o que falta.
     *
     * O `canRequestService()` responde sim ou não; o motivo já estava calculado e
     * traduzido ao lado, e só o backoffice o via. A app recebia "O cliente não pode
     * solicitar um serviço." — que não diz ao cliente o que fazer a seguir, nem se
     * o problema é dele, da morada, ou de um serviço que ficou aberto.
     *
     * Sem motivos apurados, mantém-se a frase genérica: é preferível a uma recusa
     * sem explicação nenhuma.
     *
     * @param  Collection<int, string>  $motivos
     */
    public static function comMotivos(Collection $motivos): self
    {
        return $motivos->isEmpty()
            ? new self
            : new self($motivos->implode(' '));
    }

    public function getStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
