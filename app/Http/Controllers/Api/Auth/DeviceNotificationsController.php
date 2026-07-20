<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\DeviceNotificationsRequest;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Device;

class DeviceNotificationsController extends Controller
{
    public function __invoke(DeviceNotificationsRequest $request)
    {
        $user = auth()->user();
        $expoToken = $request->get('expoPushToken');
        $deviceName = $request->get('deviceName');

        // Remove this token from any other account to prevent one device
        // being associated with multiple users simultaneously.
        Device::where('expo_token', $expoToken)
            ->where('user_id', '!=', $user->id)
            ->delete();

        $user->devices()->updateOrCreate(
            ['expo_token' => $expoToken],
            ['device_name' => $deviceName]
        );

        return new ApiSuccessResponse;
    }
}
