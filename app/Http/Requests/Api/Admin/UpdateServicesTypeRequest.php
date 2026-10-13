<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateServicesTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'operation_area_id' => ['sometimes', 'required', 'integer', 'exists:operation_areas,id'],
            'time' => ['sometimes', 'required', 'integer', 'min:0'],
            // Em EUROS e não em cêntimos — ver o comentário da coluna.
            'starts_from' => ['nullable', 'integer', 'min:0'],
            'includes' => ['nullable', 'array'],
            'includes.*' => ['string', 'max:255'],
            'excludes' => ['nullable', 'array'],
            'excludes.*' => ['string', 'max:255'],
            // Desativar tira-o da app sem apagar nada: é o caminho para um
            // serviço que já tem pedidos (ver ServicesTypeController::destroy).
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
