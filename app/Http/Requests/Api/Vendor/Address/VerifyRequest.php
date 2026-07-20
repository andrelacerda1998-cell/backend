<?php

namespace App\Http\Requests\Api\Vendor\Address;

use Illuminate\Foundation\Http\FormRequest;

class VerifyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'street_name' => 'required|string',
            'street_number' => 'required|string',
            'city' => 'required|string',
            'postal_code' => 'required|string',
        ];
    }
}
