<?php

namespace App\Http\Requests\Api\Vendor\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePriceRateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rate'=> 'required|numeric|min:0',
        ];
    }
}
