<?php

namespace App\Http\Controllers\Api\Common;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\NotificationCampaignLog;
use Symfony\Component\HttpFoundation\Response;

class CampaignNotificationTrackingController extends Controller
{
    public function open(NotificationCampaignLog $log): ApiSuccessResponse
    {
        //abort_if($log->user_id !== auth()->id(), Response::HTTP_FORBIDDEN);

        if ($log->opened_at === null) {
            $log->update(['opened_at' => now()]);
        }

        return ApiSuccessResponse::make();
    }

    public function click(NotificationCampaignLog $log): ApiSuccessResponse
    {
        //abort_if($log->user_id !== auth()->id(), Response::HTTP_FORBIDDEN);

        if ($log->deep_link_clicked_at === null) {
            $log->update(['deep_link_clicked_at' => now()]);
        }

        return ApiSuccessResponse::make();
    }
}
