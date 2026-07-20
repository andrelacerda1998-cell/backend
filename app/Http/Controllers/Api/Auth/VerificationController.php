<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\User;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    /**
     * Send an email verification link to the user.
     *
     * The user can request the send function both from the web when trying to verify the email or from the application.
     * When it has an id, it comes from the web; when it does not, it comes from the application.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function send(Request $request)
    {
        try {
            $id = $request->get('id');
            $user = auth('api')->user() ?: User::find($id);

            if (!$user) {
                throw new \Exception('Email already verified');
            } else if ($user->hasVerifiedEmail()) {
                throw new \Exception('Email already verified');
            }

            $user->sendEmailVerificationNotification();
        }catch (\Exception $exception){
            return new ApiErrorResponse($exception, 'Email already verified', 400);
        }


        // event (new Registered($user));

        return new ApiSuccessResponse(['message' => 'Email verification link sent']);
    }
}
