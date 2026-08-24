<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class StartMatchingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'service_type' => ['required', 'integer', 'exists:services_types,id'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'scheduled' => ['sometimes', 'boolean'],

            // Mesma forma que o fluxo antigo guarda em `pending_schedule_data`.
            // A hora é obrigatória e não opcional: sem ela a agenda não pode
            // ser materializada depois do pagamento, e o pedido morria já com
            // o dinheiro cobrado — o pior sítio possível para falhar.
            'schedule' => ['nullable', 'required_if:scheduled,true', 'array'],
            'schedule.scheduled_day' => ['required_if:scheduled,true', 'date'],
            'schedule.scheduled_time_start' => ['required_if:scheduled,true', 'string'],

            'customer_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
