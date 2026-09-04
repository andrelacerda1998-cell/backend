<?php

namespace App\Http\Controllers\Api\Customer\Schedule;

use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Schedule\Schedule;
use App\Notifications\Vendor\ScheduleCanceledByCustomerNotification;
use App\Services\Common\Services\CancellationPolicy;
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
                    // Penalização conforme a proximidade da hora marcada
                    // (24h/6h/1h -> 50/75/100%). A app mostra o mesmo valor ao
                    // cliente antes de confirmar; a decisão é aqui.
                    $cancelService->customerCancelScheduled(
                        CancellationPolicy::scheduledPenaltyRatio($this->scheduledAt($schedule)),
                    );
                }
            }

            $schedule->vendor->user->notify(new ScheduleCanceledByCustomerNotification($schedule));

            $schedule->delete();

            return new ApiSuccessResponse;
        } catch (Exception $e) {
            return new ApiErrorResponse($e);
        }
    }

    /**
     * Instante marcado para o serviço. Sem dia ou sem hora utilizável devolve
     * null — e sem instante não há penalização, que é o lado seguro.
     */
    private function scheduledAt(Schedule $schedule): ?\DateTimeInterface
    {
        if (! $schedule->scheduled_day) {
            return null;
        }

        try {
            $day = \Carbon\Carbon::parse($schedule->scheduled_day)->format('Y-m-d');
            $time = $schedule->scheduled_time_start ?: '00:00:00';

            return \Carbon\Carbon::parse("{$day} {$time}");
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
