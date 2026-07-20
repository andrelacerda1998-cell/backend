<?php

namespace App\Http\Controllers\Api\Customer\Address;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use Illuminate\Http\Request;

class GetCurrentAddressController extends Controller
{
    public function __invoke(Request $request)
    {
        $customer = $request->user();

        $address = $customer->addresses()->where('main_address', true)->first();

        return new ApiSuccessResponse(compact('address'));
    }
}
