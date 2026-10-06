<?php

namespace App\Http\Requests\Api\Vendor\Settings;

use App\Models\Vendor;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePriceRateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rate' => Vendor::regraDoValorHora(),
        ];
    }

    public function messages(): array
    {
        return [
            'rate.between' => Vendor::mensagemDoValorHora(),
        ];
    }
}
