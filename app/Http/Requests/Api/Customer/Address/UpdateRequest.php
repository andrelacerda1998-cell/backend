<?php

namespace App\Http\Requests\Api\Customer\Address;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'address_name' => 'nullable|string|max:50',
            'street_name' => 'nullable|string',
            'street_number' => 'nullable|string',
            'additional_info' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'country' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ];
    }
}
