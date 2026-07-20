<?php

namespace App\Http\Controllers\Api\Vendor\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\Settings\UpdatePriceRateRequest;
use App\Http\Responses\Api\ApiSuccessResponse;

class UpdatePriceRateController extends Controller
{
    public function __invoke(UpdatePriceRateRequest $request)
    {
        $rate = $request->get('rate');

        $vendor = auth()->user()->vendor;
        $vendor->update([
            'price_rate' => $rate
        ]);

        return ApiSuccessResponse::make();
    }
}
