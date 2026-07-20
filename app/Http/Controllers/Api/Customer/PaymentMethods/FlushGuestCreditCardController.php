<?php

namespace App\Http\Controllers\Api\Customer\PaymentMethods;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class FlushGuestCreditCardController extends Controller
{
    public function __invoke(Request $request)
    {
        try {
            $request->validate(['guest_token' => 'required|string']);

            $cacheKey = AddCreditCardController::guestCacheKey($request->input('guest_token'));
            $cardData = Cache::get($cacheKey);

            if (! $cardData) {
                return ApiSuccessResponse::make(['flushed' => false]);
            }

            $user = auth('api')->user();

            if (! $user->hasPayShopId()) {
                $user->createAsPayShopCustomer();
            }

            $user->addCreditCard(
                $cardData['cardNumber'],
                $cardData['cvc'],
                $cardData['expirationMonth'],
                $cardData['expirationYear'],
                $cardData['holderName']
            );

            Cache::forget($cacheKey);

            return ApiSuccessResponse::make(['flushed' => true]);
        } catch (\Exception $exception) {
            return new ApiErrorResponse($exception);
        }
    }
}
