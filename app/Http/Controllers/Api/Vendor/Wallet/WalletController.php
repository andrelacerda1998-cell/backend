<?php

namespace App\Http\Controllers\Api\Vendor\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __invoke(Request $request)
    {
        $wallet = $request->user()->wallet;

        return new ApiSuccessResponse([
            ...$wallet->only(
              'balanceFloat',
              'decimal_places',
              'name',
              'slug'
            )
        ]);
    }
}
