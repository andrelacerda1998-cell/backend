<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'name'/'description' são traduzíveis no Filament (EN + PT-PT);
            // esta fatia usa um único campo por simplicidade, gravado nas
            // duas línguas (ver DocumentController::applyTranslatable()).
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'required' => ['boolean'],
        ];
    }
}
