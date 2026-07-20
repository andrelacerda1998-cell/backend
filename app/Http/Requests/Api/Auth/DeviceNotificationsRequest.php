<?php

namespace App\Http\Requests\Api\Auth;

use Illuminate\Foundation\Http\FormRequest;
use NotificationChannels\Expo\ExpoPushToken;

class DeviceNotificationsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'expoPushToken' => ['string', ExpoPushToken::rule()],
            'deviceName' => 'string',
        ];
    }
}
