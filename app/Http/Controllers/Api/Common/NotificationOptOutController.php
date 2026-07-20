<?php

namespace App\Http\Controllers\Api\Common;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\NotificationCampaignOptOut;
use Illuminate\Http\Request;

class NotificationOptOutController extends Controller
{
    public function store(Request $request): ApiSuccessResponse
    {
        $request->validate([
            'campaign_id' => ['nullable', 'integer', 'exists:notification_campaigns,id'],
        ]);

        NotificationCampaignOptOut::firstOrCreate([
            'user_id' => auth()->id(),
            'notification_campaign_id' => $request->input('campaign_id'),
        ]);

        return ApiSuccessResponse::make();
    }

    public function destroy(Request $request): ApiSuccessResponse
    {
        $request->validate([
            'campaign_id' => ['nullable', 'integer', 'exists:notification_campaigns,id'],
        ]);

        NotificationCampaignOptOut::where('user_id', auth()->id())
            ->where('notification_campaign_id', $request->input('campaign_id'))
            ->delete();

        return ApiSuccessResponse::make();
    }
}
