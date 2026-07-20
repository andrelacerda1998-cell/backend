<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Auth\PhoneNumberValidationCode;
use Illuminate\Http\Request;

class ValidatePhoneNumberController extends Controller
{
    public function __invoke(Request $request)
    {
        $code = $request->get('code', null);
        if (! $code) {
            abort(403);
        }
        $user = auth()->user();
        if (config('app.MOCK_SMS')) {
            if ($code === '123456') {
                $user->update(['phone_number_verified_at' => now(), 'profile_completion_pending' => false]);

                return ApiSuccessResponse::make([
                    'verified_at' => $user->phone_number_verified_at,
                ]);
            }
            abort(403, 'Invalid code');
        }

        if (PhoneNumberValidationCode::where('user_id', $user->id)
            ->where('created_at', '>', now()->subMinutes(5))
            ->where('code', $code)
            ->exists()) {
            $user->update(['phone_number_verified_at' => now(), 'profile_completion_pending' => false]);

            return ApiSuccessResponse::make([
                'verified_at' => $user->phone_number_verified_at,
            ]);
        } else {
            abort(403);
        }
    }
}
