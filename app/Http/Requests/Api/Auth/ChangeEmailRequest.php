<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeEmailRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email',
                'max:255',
                // Ignora o proprio registo: pedir de novo o email que ja se
                // tem e um caso legitimo (reenviar a confirmacao), nao um
                // conflito.
                Rule::unique('users', 'email')->ignore($this->user()?->id),
                // Mesma guarda do registo: os enderecos `imp.<id>@piquetapp.pt`
                // sao contas de importacao e nao pertencem a ninguem.
                'not_regex:/^imp\.\d+@piquetapp\.pt$/i',
            ],
        ];
    }

    public function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => trim(mb_strtolower($this->input('email')))]);
        }
    }
}
