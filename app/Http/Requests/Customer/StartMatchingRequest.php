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
            'scheduled_day' => ['required_if:scheduled,true', 'nullable', 'date'],
            'customer_notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
