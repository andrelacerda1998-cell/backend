<?php

namespace App\Http\Controllers\Api\Common;

use App\Exceptions\Api\Common\WrongAppVersion;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Common\AppNeedsUpdateRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use Illuminate\Support\Facades\Hash;

class AppNeedsUpdateController extends Controller
{
    public function __invoke(AppNeedsUpdateRequest $request): ApiErrorResponse|ApiSuccessResponse
    {
        try {
            $version = $request->get('version');
            $platform = $request->get('platform');

            $minimumVersions = config('app.minimum_app_version');
            $minimumVersion = $minimumVersions[$platform] ?? '1.0.0';

            if (version_compare($version, $minimumVersion, '<')) {
                throw new WrongAppVersion();
            }

            return ApiSuccessResponse::make();
        }catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }

    }
}
