<?php

namespace App\Rules;

use App\Services\Payments\JanelaDeCativacao;
use Carbon\Carbon;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Não se agenda para depois de a cativação do cartão expirar.
 *
 * `scheduled_day` era validado apenas como `date`, sem limite superior, e o
 * `DatePicker` da app não passava `maximumDate`: dava para marcar um serviço
 * para dentro de dois meses. O dinheiro fica cativo
 * `JanelaDeCativacao::DIAS` dias; passado esse prazo o `confirm()` do fecho
 * falha, o serviço cai em CLOSED_PENDING_PAYMENT e o técnico fez o trabalho
 * sem receber.
 *
 * O limite sai sempre da `JanelaDeCativacao` e nunca de um número escrito aqui.
 */
class AgendamentoDentroDaJanelaDePagamento implements ValidationRule
{
    public function validate(string $attribute, $value, Closure $fail): void
    {
        // Campo opcional nos pedidos imediatos: sem data não há nada a validar,
        // e o `date`/`required_if` ao lado é que trata do formato.
        if ($value === null || $value === '') {
            return;
        }

        try {
            $dia = Carbon::parse($value);
        } catch (\Throwable) {
            return; // formato inválido é problema da regra `date`, não desta
        }

        $limite = JanelaDeCativacao::ultimoDiaAgendavel();

        if ($dia->greaterThan($limite)) {
            $fail(__('request/validation.agendamento_fora_da_janela', [
                'data' => $limite->format('d/m/Y'),
            ]));
        }
    }
}
