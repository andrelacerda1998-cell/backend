<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\User;
use Exception;
use Filament\Events\Auth\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;

class VerificationController extends Controller
{
    public function __invoke(string $id, string $hash)  
    {
        $user = User::find($id);

        if (
            !$user ||
            !hash_equals((string) $user->getKey(), (string) $id) ||
            !hash_equals(sha1($user->getEmailForVerification()), (string) $hash)
        ) {
            return view('auth.verify-email', ['status' => 'invalid', 'id' => $id]);
        }

        if ($user->hasVerifiedEmail()) {
            return view('auth.verify-email', ['status' => 'already-verified']);
        } else {
            try {
                $this->fulfill($user);

                return view('auth.verify-email', ['status' => 'email-verified']);
            } catch (Exception) {
                return view('auth.verify-email', ['status' => 'invalid', 'id' => $id]);
            }
        }

    }

    public function fulfill(User $user)
    {
        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();

            // event(new Verified($user));
        }
    }

    /**
     * Resend an email verification link to the user.
     *
     * The user can request the resend function both from the web when trying to verify the email or from the application.
     * When it has an id, it comes from the web; when it does not, it comes from the application.
     *
     * @param \Illuminate\Http\Request $request
     */
    public function resend(Request $request)
    {
        $id = $request->get('id');
        $user = auth('api')->user() ?: User::find($id);

        if (!$user) {
            return new ApiErrorResponse(null,'User not found', 404);
        } else if ($user->hasVerifiedEmail()) {
            return new ApiErrorResponse(null, 'Email already verified', 400);
        }

        $user->sendEmailVerificationNotification();

        // event (new Registered($user));

        return new ApiSuccessResponse(['message' => 'Email verification link sent']);
    }
}
