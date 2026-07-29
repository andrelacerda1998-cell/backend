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
                // Mesma regra do registo: sem regras de composição, só comprimento
                // mínimo + verificação contra listas de fugas de dados reais.
                Password::min(8)->uncompromised(),
            ],
        ];
    }
}
