<?php

namespace App\Http\Controllers\Api\Vendor\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\Settings\UpdatePaymentRequest;
use App\Http\Responses\Api\ApiSuccessResponse;

class UpdatePaymentController extends Controller
{
    public function update(UpdatePaymentRequest $request)
    {
        $iban = $request->get('iban');
        $rate = $request->get('price_rate');

        $vendor = auth()->user()->vendor;

        // O nome fiscal e exigido pelo InvoiceXpress (a conta e criada com o
        // organization_name antes de a AT entrar em jogo). Quando a app nao o
        // envia — no registo deixou de o pedir — preenchemo-lo: mantemos o que
        // ja houver, senao usamos o nome do registo do tecnico (certo para
        // recibos verdes). Quem fatura por empresa edita-o em Definicoes.
        $companyName = $request->filled('company_name')
            ? $request->get('company_name')
            : ($vendor->company_name ?: trim((string) $vendor->user->name));
        $vendor->update([
            'iban'=> $iban,
            'price_rate' => $rate,
            'company_name'=> $companyName,
        ]);

        return new ApiSuccessResponse([
            'iban'=> $vendor->iban,
            'price_rate' => $vendor->price_rate,
            'company_name' => $vendor->company_name,
        ]);
    }
}
