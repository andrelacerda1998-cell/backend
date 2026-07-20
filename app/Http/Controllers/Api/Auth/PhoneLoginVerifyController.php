<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\Api\User\WrongApp;
use App\Exceptions\Api\User\WrongCredentials;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\PhoneLoginVerifyRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\Auth\LoginApiResponse;
use App\Services\Common\PhoneLoginSmsService;

class PhoneLoginVerifyController extends Controller
{
    public function __invoke(PhoneLoginVerifyRequest $request, PhoneLoginSmsService $service)
    {
        try {
            $phoneNumber = $request->get('phone_number');
            $code = $request->get('code');

            if (config('app.MOCK_SMS') && $code !== '123456') {
                throw new WrongCredentials;
            }

            if (! config('app.MOCK_SMS')) {
                $user = $service->verifyCode($phoneNumber, $code);

                if (! $user) {
                    throw new WrongCredentials;
                }
            } else {
                $user = $service->findUserByPhone($phoneNumber);

                if (! $user) {
                    throw new WrongCredentials;
                }
            }

            if (! $user->isCustomer()) {
                throw new WrongApp;
            }

            $token = auth('api')->login($user);

            return new LoginApiResponse($token, ['message' => 'Login successful']);
        } catch (\Exception $exception) {
            return new ApiErrorResponse($exception);
        }
    }
}
