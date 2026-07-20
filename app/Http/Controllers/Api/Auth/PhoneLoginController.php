<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\PhoneLoginRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Services\Common\PhoneLoginSmsService;

class PhoneLoginController extends Controller
{
    public function __invoke(PhoneLoginRequest $request, PhoneLoginSmsService $service)
    {
        try {
            $phoneNumber = $request->get('phone_number');

            $result = $service->sendCode($phoneNumber);

            if (config('app.MOCK_SMS') && ! app()->isProduction()) {
                return new ApiSuccessResponse([
                    'message' => 'Code sent',
                    'mock_code' => $result['mock_code'],
                ]);
            }

            return new ApiSuccessResponse(['message' => 'Code sent']);
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }

    }
}
