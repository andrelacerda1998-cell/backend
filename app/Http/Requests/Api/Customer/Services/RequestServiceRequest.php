<?php

namespace App\Http\Requests\Api\Customer\Services;

use Illuminate\Foundation\Http\FormRequest;

class RequestServiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'service_type' => 'required|exists:App\Models\GeneralSettings\ServicesType,id,deleted_at,NULL',
            // Dia e hora do trabalho, quando ja escolhidos. A lista mostra
            // precos, e a sobretaxa horaria e a do servico: sem eles a lista
            // cotava com a hora de agora e discordava do checkout.
            'scheduled_day' => 'date|nullable|required_with:scheduled_time_start',
            'scheduled_time_start' => 'string|nullable|required_with:scheduled_day',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
