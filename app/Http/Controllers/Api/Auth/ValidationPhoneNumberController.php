<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\SmsType;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Auth\PhoneNumberValidationCode;
use App\Services\Common\SmsValidationService;

class ValidationPhoneNumberController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();

        if ($user->phone_number_verified_at) {
            abort(403);
        }

        if (PhoneNumberValidationCode::where('user_id', $user->id)->where('created_at', '>', now()->subMinutes(5))->exists()) {
            abort(400);
        }

        if (config('app.MOCK_SMS')){
            return ApiSuccessResponse::make();
        }
        $service = new SmsValidationService($user);
        $service->sendValidationCode(SmsType::VERIFICATION);

        return ApiSuccessResponse::make();
    }
}
