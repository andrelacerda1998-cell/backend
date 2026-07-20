<?php

namespace App\Http\Controllers\Api\Common;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;

class AppVersionController extends Controller
{
    /**
     * Public endpoint that returns the minimum supported app version per app
     * and platform. The mobile apps fetch this on startup and block themselves
     * when their own version is below the returned minimum.
     */
    public function __invoke(): ApiSuccessResponse
    {
        return ApiSuccessResponse::make(config('app.minimum_app_version'));
    }
}
