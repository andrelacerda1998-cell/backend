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
            'method' => ['sometimes', 'in:credit_card,mbway,apple_pay,google_pay'],
            // O que a carteira devolveu ao telemovel, tal e qual. Vai inteiro
            // para o Payshop, que e quem tem a chave para o decifrar — nos nao
            // lhe tocamos, e por isso nao ha aqui regras sobre o conteudo.
            'wallet_payload' => ['required_if:method,apple_pay', 'required_if:method,google_pay', 'array'],
            'voucher_id' => ['sometimes', 'nullable', 'integer', 'exists:vouchers,id'],
        ];
    }
}
