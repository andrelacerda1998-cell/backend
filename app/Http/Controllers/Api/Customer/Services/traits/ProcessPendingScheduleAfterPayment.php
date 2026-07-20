<?php

namespace App\Http\Controllers\Api\Customer\Services\traits;

use App\Jobs\Services\CancelJobWithoutReactionJob;
use App\Models\Service;
use App\Services\Common\Services\MaterializePendingSchedule;

trait ProcessPendingScheduleAfterPayment
{
    private function processPendingScheduleAfterPayment(Service $service): void
    {
        // Delegates to MaterializePendingSchedule (single source of truth) so the customer-app
        // poll and MbwayPaymentCheckJob share identical post-payment behaviour.
        app(MaterializePendingSchedule::class)->handle($service);
    }

    private function dispatchCancelJob(Service $service): void
    {
        $seconds = config('services.request.time_to_accept');
        $service->refresh();
        $service->load('schedule');
        if ($service->schedule) {
            $seconds = config('services.request.time_accept_scheduled');
        }

        CancelJobWithoutReactionJob::dispatch($service)->delay(
            now()->addSeconds($seconds)
        );
    }
}
