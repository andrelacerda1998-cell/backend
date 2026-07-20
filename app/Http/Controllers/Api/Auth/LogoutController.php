<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;

class LogoutController extends Controller
{
    public function __invoke()
    {
        try {
            if (\Auth::check())
            auth()->logout(true, true);

            return new ApiSuccessResponse;
        }catch (\Exception){
            return new ApiSuccessResponse();
        }
    }
}
