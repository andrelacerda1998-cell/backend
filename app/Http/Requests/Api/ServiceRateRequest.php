<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ServiceRateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rate' => 'required|integer|between:1,5',
            // Opcional: so o cliente escreve comentario, e mesmo ele pode dar
            // so a estrela. O teto existe porque a coluna e `text` e o que
            // entra vai ser mostrado ao tecnico.
            'comment' => 'nullable|string|max:1000',
        ];
    }
}
