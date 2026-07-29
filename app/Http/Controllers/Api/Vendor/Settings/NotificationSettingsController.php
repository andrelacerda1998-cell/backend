<?php

namespace App\Http\Controllers\Api\Vendor\Settings;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use Illuminate\Http\Request;

class NotificationSettingsController extends Controller
{
    /** Tipos de notificação que o técnico pode ligar/desligar. */
    private const KEYS = ['new_requests', 'schedule_reminders', 'messages', 'payments', 'news'];

    private function defaults(): array
    {
        return collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => true])->all();
    }

    public function show(Request $request)
    {
        $vendor = $request->user()->vendor;
        $prefs = array_merge($this->defaults(), (array) ($vendor->notification_preferences ?? []));

        return new ApiSuccessResponse(['notification_preferences' => $prefs]);
    }

    public function update(Request $request)
    {
        $rules = collect(self::KEYS)->mapWithKeys(fn ($k) => [$k => 'sometimes|boolean'])->all();
        $validated = $request->validate($rules);

        $vendor = $request->user()->vendor;
        $prefs = array_merge($this->defaults(), (array) ($vendor->notification_preferences ?? []), $validated);

        $vendor->update(['notification_preferences' => $prefs]);

        return new ApiSuccessResponse(['notification_preferences' => $prefs]);
    }
}
