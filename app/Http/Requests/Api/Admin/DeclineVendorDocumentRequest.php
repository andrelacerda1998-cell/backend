<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DeclineVendorDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A autorização já foi feita pelo middleware admin.api (rota inteira).
        return true;
    }

    public function rules(): array
    {
        return [
            // Obrigatório, tal como no Textarea ->required() do Filament — vai
            // para o email do técnico via DenyNotification.
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
