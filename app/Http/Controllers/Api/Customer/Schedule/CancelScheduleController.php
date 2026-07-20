<?php

namespace App\Http\Controllers\Api\Customer\Schedule;

use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Schedule\Schedule;
use App\Notifications\Vendor\ScheduleCanceledByCustomerNotification;
use App\Services\Common\Services\CancelService;
use Exception;

class CancelScheduleController extends Controller
{
    public function __invoke(Schedule $schedule)
    {
        try {
            if ($schedule->customer_id !== auth()->user()->id) {
                throw new Exception('Schedule not found', 404);
            }

            $service = $schedule->service;

            if ($service) {
                $cancelService = new CancelService($service);

                if ($service->status === ServiceStatus::PENDING || $service->status === ServiceStatus::SCHEDULED) {
                    $cancelService->customerCancel();
                }
            }

            $schedule->vendor->user->notify(new ScheduleCanceledByCustomerNotification($schedule));

            $schedule->delete();

            return new ApiSuccessResponse;
        } catch (Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
