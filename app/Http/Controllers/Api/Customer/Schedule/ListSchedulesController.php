<?php

namespace App\Http\Controllers\Api\Customer\Schedule;

use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Schedule\Schedule;
use Illuminate\Support\Carbon;

class ListSchedulesController extends Controller
{
    public function __invoke(): ApiSuccessResponse
    {
        $user = auth()->user();

        $schedulesQuery = $user->schedules()
            ->with(['vendor.user', 'serviceType', 'service']);

        $schedulesLength = $schedulesQuery->count();
        $limit = 10;
        $offset = request('offset', 0);
        $hasMore = $schedulesLength > ($offset + $limit);

        $schedules = $schedulesQuery
            ->orderByDesc('scheduled_day')
            ->orderByDesc('scheduled_time_start')
            ->offset($offset)
            ->where('scheduled_day', '>=', Carbon::now()->startOfDay())
            ->whereHas('service', function ($query) {
                $query->whereIn('status', [ServiceStatus::ACCEPTED, ServiceStatus::SCHEDULED]);
            })
            ->limit($limit)
            ->get();

        $schedules = $schedules->transform(function (Schedule $schedule) {
            return [
                'id' => $schedule->id,
                'status' => $schedule->is_pending ? 'pending' : 'accepted',
                'scheduled_day' => Carbon::parse($schedule->scheduled_day)->format('Y-m-d'),
                'scheduled_time_start' => Carbon::parse($schedule->scheduled_time_start)->format('H:i'),
                'scheduled_time_end' => Carbon::parse($schedule->scheduled_time_end)->format('H:i'),
                'service_id' => $schedule->service_id,
                'price' => number_format(($schedule->service->amount/100), 2, '.'),
                'vendor' => $schedule->vendor ? [
                    'id' => $schedule->vendor->id,
                    'name' => $schedule->vendor->user->name,
                    'avatar' => $schedule->vendor->user->avatar,
                    // Contacto do técnico só depois de aceite/confirmado — em pending
                    // ainda não há compromisso e não expomos o número.
                    'phone' => $schedule->is_pending ? null : $schedule->vendor->user->phone_number,
                ] : null,
                'service_type' => $schedule->serviceType ? [
                    'id' => $schedule->serviceType->id,
                    'name' => $schedule->serviceType->name,
                ] : null,
                'address' => $schedule->service->address?$schedule->service->address['name']  : null,
                'created_at' => $schedule->created_at,
            ];
        });

        return new ApiSuccessResponse(compact('schedules', 'hasMore'));
    }
}
