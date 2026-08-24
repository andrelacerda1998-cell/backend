<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class MatchingCheckoutRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'payment_method' => ['sometimes', 'nullable', 'integer'],
            'mbway_phone' => ['required_if:method,mbway', 'nullable', 'string'],
            'method' => ['sometimes', 'in:credit_card,mbway'],
            'voucher_id' => ['sometimes', 'nullable', 'integer', 'exists:vouchers,id'],
        ];
    }
}
