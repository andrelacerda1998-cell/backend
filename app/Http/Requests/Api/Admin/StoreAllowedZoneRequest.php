<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreAllowedZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // No Filament, 'city' vem de um autocomplete do Google Places
            // (restrito a Portugal), que também preenche 'district'. Esta
            // fatia usa dois campos de texto livre (decisão explícita,
            // 2026-07-29) -- sem validação de unicidade, tal como a tabela
            // não tem esse constraint hoje.
            'city' => ['required', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
        ];
    }
}
