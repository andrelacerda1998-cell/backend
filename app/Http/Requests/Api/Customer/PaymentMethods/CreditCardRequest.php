<?php

namespace App\Http\Requests\Api\Customer\PaymentMethods;

use Illuminate\Foundation\Http\FormRequest;

class CreditCardRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'creditCardData' => 'required',
        ];
    }
}
