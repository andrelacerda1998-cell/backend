<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class ServiceRateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rate' => 'required|integer|between:1,5',
        ];
    }
}
