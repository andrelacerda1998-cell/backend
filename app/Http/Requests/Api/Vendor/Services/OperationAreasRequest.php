<?php

namespace App\Http\Requests\Api\Vendor\Services;

use Illuminate\Foundation\Http\FormRequest;

class OperationAreasRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'operation_areas' => 'array|nullable',
            'operation_areas.*' => 'exists:operation_areas,id',
        ];
    }
}
