<?php

namespace App\Http\Controllers\Api\Auth\Guest;

use App\Enums\SmsType;
use App\Http\Controllers\Controller;
use App\Models\Auth\PhoneNumberValidationCode;
use App\Services\Common\PhoneLoginSmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class GuestVerifyOtpController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string',
            'code' => 'required|string|size:6',
        ]);

        $phoneNumber = PhoneLoginSmsService::normalizePhoneNumber($request->phone_number);

        // Anti-brute-force por número: um código de 6 dígitos com janela de 5 min é
        // adivinhável com tentativas em massa. Bloqueia após 5 falhas; o utilizador pede
        // um código novo. Fail-open: sem cache, o default 0 não bloqueia ninguém.
        $failKey = "otp_fail:{$phoneNumber}";

        if (Cache::get($failKey, 0) >= 5) {
            return response()->json(['message' => 'Too many invalid attempts. Please request a new code.'], 429);
        }

        $validation = PhoneNumberValidationCode::where('phone_number', $phoneNumber)
            ->where('type', SmsType::VERIFICATION)
            ->where('code', $request->code)
            ->where('created_at', '>', now()->subMinutes(5))
            ->first();

        if (! $validation) {
            Cache::put($failKey, Cache::get($failKey, 0) + 1, now()->addMinutes(10));

            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        Cache::forget($failKey);
        $validation->delete();

        $verificationToken = Str::uuid()->toString();

        Cache::put(
            "guest_phone_verified:{$phoneNumber}",
            $verificationToken,
            now()->addMinutes(10)
        );

        return response()->json(['data' => ['verification_token' => $verificationToken]]);
    }
}
