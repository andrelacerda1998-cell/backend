<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\Api\Common\WrongAppVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LocaleRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use Illuminate\Support\Facades\Hash;

class LocaleController extends Controller
{
    public function __invoke(LocaleRequest $request): ApiErrorResponse|ApiSuccessResponse
    {
        try {
            $language = $request->get('language');

            $user = auth('api')->user();

            if (!$user){
                return ApiSuccessResponse::make();
            }

            $fallback_locale = config('app.fallback_locale');

            if ($language !== $user->language) {
                $user->update(['language' => $language]);
                app()->setLocale($language ?? $fallback_locale);
            }

            return ApiSuccessResponse::make();
        }catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }

    }
}
