<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreServicesTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // 'name' é traduzível no Filament (campos EN + PT-PT separados). Esta
            // fatia do backoffice usa um único campo -- o valor é gravado em
            // ambas as línguas (ver ServicesTypeController::translatedName()).
            'name' => ['required', 'string', 'max:255'],
            'operation_area_id' => ['required', 'integer', 'exists:operation_areas,id'],
            'time' => ['required', 'integer', 'min:0'],
            'starts_from' => ['nullable', 'integer', 'min:0'],
            // Idem 'includes'/'excludes': no Filament cada item tem EN + PT-PT;
            // aqui é uma lista simples de texto, gravada nas duas línguas.
            'includes' => ['nullable', 'array'],
            'includes.*' => ['string', 'max:255'],
            'excludes' => ['nullable', 'array'],
            'excludes.*' => ['string', 'max:255'],
        ];
    }
}
