<?php

namespace App\Http\Requests\Api\Vendor\Settings;

use App\Rules\IbanRule;
use App\Rules\NifRule;
use Illuminate\Foundation\Http\FormRequest;
use Nembie\IbanRule\ValidIban;

class UpdatePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'iban' => ['required', new ValidIban()],
            'price_rate' => 'required|nullable',
            'company_name' => 'required|string|max:255',
            // 'nif' => ['string', new NifRule],
        ];
    }
}
