<?php

namespace App\Services\Common;

use App\Enums\SmsType;
use App\Models\Auth\PhoneNumberValidationCode;
use App\Models\User;
use App\Notifications\Auth\PhoneNumberValidationNotification;

class SmsValidationService
{
    public function __construct(private User $user) {}

    public function sendValidationCode(SmsType $type): void
    {
        $code = rand(100000, 999999);

        if ($type === SmsType::VERIFICATION) {
            // Persistir SINCRONAMENTE (a notificação corre em fila — ShouldQueue).
            // Sem isto, se o worker de fila não correr, o toTwilio() nunca gravava a
            // linha e o ValidatePhoneNumber devolvia sempre 403 → loop no ecrã SMS.
            $sms = new PhoneNumberValidationCode();
            $sms->user_id = $this->user->id;
            $sms->type = SmsType::VERIFICATION;
            $sms->code = $code;
            $sms->save();

            $this->user->notify(new PhoneNumberValidationNotification($code));
        }
    }
}
