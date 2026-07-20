<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;

class PhoneLoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'phone_number' => 'required|string',
        ];
    }
}
