<?php

namespace App\Services\Common\Services;

use RwInteractive\PayshopSdk\Models\PaymentOrder;

/**
 * Pergunta ao Payshop em que estado está uma ordem e grava-o.
 *
 * Um sítio só para esta chamada, para os testes a poderem trocar: o SDK cria
 * o cliente HTTP por dentro, e nos testes o gateway não existe. Sem isto, o
 * caminho que decide o que fazer a um MB Way (confirmar, recusar, expirar)
 * só se testava aos bocados, por reflexão.
 */
class ConsultarOrdemPayshop
{
    public function atualizar(PaymentOrder $ordem): PaymentOrder
    {
        return $ordem->updateData();
    }
}
