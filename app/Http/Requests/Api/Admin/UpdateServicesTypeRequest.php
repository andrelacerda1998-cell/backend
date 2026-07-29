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
            'starts_from' => ['nullable', 'integer', 'min:0'],
            'includes' => ['nullable', 'array'],
            'includes.*' => ['string', 'max:255'],
            'excludes' => ['nullable', 'array'],
            'excludes.*' => ['string', 'max:255'],
        ];
    }
}
