<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreOperationAreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'name' é traduzível no Filament (EN + PT-PT); aqui é um único
            // campo, gravado nas duas línguas (ver OperationAreaController).
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
