<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;

class GuestRegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'phone_number' => 'required|string',
            'verification_token' => 'required|string',
            'address' => 'required|array',
            'address.latitude' => 'required|numeric',
            'address.longitude' => 'required|numeric',
            'address.name' => 'nullable|string',
            'address.street_name' => 'nullable|string',
            'address.street_number' => 'nullable|string',
            'address.additional_info' => 'nullable|string',
            'address.postal_code' => 'nullable|string',
            'address.city' => 'nullable|string',
            'address.state' => 'nullable|string',
            'address.country' => 'nullable|string',
        ];
    }
}
