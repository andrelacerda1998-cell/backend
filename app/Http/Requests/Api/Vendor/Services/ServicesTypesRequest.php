<?php

namespace App\Http\Requests\Api\Vendor\Services;

use Illuminate\Foundation\Http\FormRequest;

class ServicesTypesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'services' => 'array',
            'services.*.id' => 'exists:App\Models\GeneralSettings\ServicesType,id'
        ];
    }
}
