<?php

namespace App\Http\Requests\Customer;

use App\Http\Requests\Common\PlanOrderRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Pedir um cesto: uma encomenda com uma ou mais visitas.
 *
 * `expected_visits` é o plano que o cliente viu e aceitou (os tipos de cada
 * visita). O servidor volta a fazer o plano e, se mudou, não cria nada:
 * nenhum cesto se divide sem o cliente ter visto a divisão.
 */
class StartOrderRequest extends FormRequest
{
    public function rules(): array
    {
        return array_merge(PlanOrderRequest::regrasDasLinhas(), [
            'address_id' => ['nullable', 'integer', 'exists:addresses,id'],
            'customer_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'expected_visits' => ['required', 'array', 'min:1'],
            'expected_visits.*' => ['required', 'array', 'min:1'],
            'expected_visits.*.*' => ['integer'],
        ], PlanOrderRequest::regrasDaHora());
    }
}
