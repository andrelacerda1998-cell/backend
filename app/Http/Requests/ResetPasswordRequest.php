<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'token' => 'required',
            'email' => 'required|email',
            'password' => [
                'required',
                'string',
                'confirmed',
                // Password::min(12)
                //     ->letters()
                //     ->mixedCase()
                //     ->numbers()
                //     ->symbols()
                //     ->uncompromised(),
            ],
        ];
    }
}
