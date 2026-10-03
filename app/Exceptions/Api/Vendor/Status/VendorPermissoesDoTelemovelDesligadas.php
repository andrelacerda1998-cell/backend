<?php

namespace App\Exceptions\Api\Vendor\Status;

use Illuminate\Http\Response;

/**
 * Recusa de ir online com a localização ou as notificações desligadas.
 *
 * O servidor não vê as permissões do telemóvel: quem as conhece é a app, que as
 * manda no pedido. A app já recusa antes de chegar aqui; isto é a segunda linha,
 * para um cliente antigo ou alterado não pôr online um técnico que não vai ver
 * os pedidos.
 *
 * 403 e não 422. Na app do técnico, QUALQUER 422 ao mudar de estado aparece como
 * "conta em verificação" -- e ele ia procurar o problema no sítio errado.
 */
class VendorPermissoesDoTelemovelDesligadas extends \Exception
{
    public function __construct(string $message = 'exceptions.vendor.phone_permissions_off')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_FORBIDDEN;
    }
}
