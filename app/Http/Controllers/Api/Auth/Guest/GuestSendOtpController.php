<?php

namespace App\Http\Controllers\Api\Auth\Guest;

use App\Enums\SmsType;
use App\Http\Controllers\Controller;
use App\Models\Auth\PhoneNumberValidationCode;
use App\Notifications\Auth\GuestPhoneOtpNotification;
use App\Services\Common\PhoneLoginSmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class GuestSendOtpController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'phone_number' => 'required|string',
        ]);

        $phoneNumber = PhoneLoginSmsService::normalizePhoneNumber($request->phone_number);

        $recentExists = PhoneNumberValidationCode::where('phone_number', $phoneNumber)
            ->where('type', SmsType::VERIFICATION)
            ->where('created_at', '>', now()->subMinutes(5))
            ->exists();

        if ($recentExists) {
            return response()->json(['message' => 'Code already sent recently. Please wait 5 minutes.'], 422);
        }

        $code = (string) random_int(100000, 999999);

        PhoneNumberValidationCode::create([
            'user_id' => null,
            'phone_number' => $phoneNumber,
            'code' => $code,
            'type' => SmsType::VERIFICATION,
        ]);

        // MOCK_SMS devolve o código ao cliente (auto-preenche o OTP) — só pode existir
        // fora de produção. Guarda dupla: mesmo que MOCK_SMS fique ligado por engano no
        // ambiente de produção, o código nunca é exposto.
        if (config('app.MOCK_SMS') && ! app()->isProduction()) {
            return response()->json(['data' => ['mock_code' => $code]]);
        }

        Notification::route('twilio', $phoneNumber)
            ->notify(new GuestPhoneOtpNotification($code));

        return response()->json(['data' => ['success' => true]]);
    }
}
