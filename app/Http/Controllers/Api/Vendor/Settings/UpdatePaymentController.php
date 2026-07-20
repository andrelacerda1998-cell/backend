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
        $companyName = $request->get('company_name');

        $vendor = auth()->user()->vendor;
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
