<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;

class PhoneLoginVerifyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'phone_number' => 'required|string',
            'code' => 'required|string|size:6',
        ];
    }
}
