<?php

namespace App\Http\Requests\Api\Customer\Services;

use Illuminate\Foundation\Http\FormRequest;

class RequestServiceRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'service_type' => 'required|exists:App\Models\GeneralSettings\ServicesType,id,deleted_at,NULL',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
