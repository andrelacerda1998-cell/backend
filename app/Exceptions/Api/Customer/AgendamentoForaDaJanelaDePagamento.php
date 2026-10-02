<?php

namespace App\Exceptions\Api\Customer;

use Illuminate\Http\Response;

/**
 * Recusa de PAGAMENTO de um agendado marcado para fora da janela cobrável.
 *
 * A validação do `scheduled_day` já recusa estas datas na criação. Esta exceção
 * é a segunda linha: um serviço pode CHEGAR ao pagamento com uma data dessas
 * sem passar por essa validação -- criado antes de o limite existir, vindo de
 * uma marcação pendente, ou por um caminho novo que alguém acrescente sem se
 * lembrar da regra.
 *
 * Pagar nesse caso é cativar dinheiro que expira antes do serviço: o técnico
 * faz o trabalho e a captura falha no fecho. Mais vale recusar o pagamento.
 */
class AgendamentoForaDaJanelaDePagamento extends \Exception
{
    public function __construct(string $message = 'exceptions.services.schedule_outside_payment_window')
    {
        parent::__construct($message);
    }

    public function getStatus(): int
    {
        return Response::HTTP_UNPROCESSABLE_ENTITY;
    }
}
