<?php

namespace App\Http\Requests\Api\Vendor\Settings;

use App\Models\Vendor;
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
            // Era 'required|nullable', sem confirmar que era número nem limites:
            // por aqui entraram valores de 1 €/h. Ver Vendor::VALOR_HORA_MINIMO.
            'price_rate' => Vendor::regraDoValorHora(),
            // Deixa de ser obrigatorio: no registo, a app ja nao pede o nome
            // fiscal (para recibos verdes e o nome do tecnico). Quando nao vem,
            // o controller preenche-o com o nome do registo. O ecra de edicao no
            // perfil continua a envia-lo para quem fatura por empresa.
            'company_name' => 'nullable|string|max:255',
            // 'nif' => ['string', new NifRule],
        ];
    }

    public function messages(): array
    {
        return [
            'price_rate.between' => Vendor::mensagemDoValorHora(),
        ];
    }
}
