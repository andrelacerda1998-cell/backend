<?php

namespace App\Http\Controllers\Api\Customer\Schedule;

use App\DTO\Schedule\ScheduleEventData;
use App\Events\Customer\Schedule\AcceptScheduleEvent;
use App\Events\Vendor\Schedule\CreateScheduleEvent;
use App\Events\Vendor\Schedule\ServiceScheduledEvent;
use App\Exceptions\Api\Vendor\Service\ServiceIsNotPending;
use App\Http\Controllers\Api\Customer\Services\traits\NotifyVendor;
use App\Http\Requests\Api\Customer\Schedule\StoreScheduleRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Vendor\NewScheduledServiceNotification;
use App\Repository\Schedule\ScheduleRepository;
use App\Services\Common\Services\AcceptService;
use Illuminate\Support\Carbon;

class ScheduleController
{
    use NotifyVendor;

    public function __construct(
        private readonly AcceptService $acceptService,
        private readonly ScheduleRepository $scheduleRepository,
    ) {}

    public function vendor(int $vendorId): ApiSuccessResponse
    {
        $vendor = Vendor::query()->findOrFail($vendorId);

        $vendorScheduleSettings = $vendor->scheduleAvailable()
            ->select('schedule_available.id', 'schedule_available.vendor_id', 'day_id', 'auto_accept', 'time_start', 'time_end', 'is_enabled')
            ->with('scheduleDay:schedule_days.id,day_name')
            ->get();

        $schedules = $vendor->schedules()
            ->select('scheduled_day', 'scheduled_time_start', 'scheduled_time_end', 'is_pending')
            ->whereDate('scheduled_day', '>=', Carbon::now())
            ->get();
        $schedules->transform(function ($schedule) {
            $schedule->scheduled_time_start = Carbon::parse($schedule->scheduled_time_start)->format('H:i');
            if (! $schedule->is_pending) {
                $schedule->scheduled_time_end = Carbon::parse($schedule->scheduled_time_end)
                    ->addMinutes((int) config('services.request.schedule_safety_margin_minutes', 60))
                    ->format('H:i');
            } else {
                $schedule->scheduled_time_end = Carbon::parse($schedule->scheduled_time_end)->format('H:i');
            }

            unset($schedule->is_pending);

            return $schedule;
        });

        return new ApiSuccessResponse(compact('vendorScheduleSettings', 'schedules'));
    }

    /**
     * @throws ServiceIsNotPending
     */
    public function createPendingSchedule(StoreScheduleRequest $request): ApiErrorResponse|ApiSuccessResponse
    {
        $vendorId = $request->input('vendor_id');
        $customerId = auth()->user()->id;
        $scheduledDay = $request->input('scheduled_day');
        $serviceId = $request->input('service_id');

        $customer = User::query()->findOrFail($customerId);
        if (! $customer->canRequestService()) {
            return new ApiErrorResponse(new \Exception('Customer cannot request service'), 'Customer cannot request service', 400);
        }

        $vendor = Vendor::query()->findOrFail($vendorId);

        $schedule = $vendor->schedules()
            ->where('customer_id', $customerId)
            ->where('scheduled_day', $scheduledDay)
            ->where('service_type_id', $request->input('service_type_id'))
            ->exists();
        if ($schedule) {
            return new ApiErrorResponse(new \Exception('Schedule already exists'), 'Schedule already exists', 400);
        }

        /** @var Schedule $schedule */
        $schedule = $vendor->schedules()
            ->create([
                'customer_id' => $customerId,
                'scheduled_day' => Carbon::parse($scheduledDay),
                'service_type_id' => $request->input('service_type_id'),
                'service_id' => $serviceId,
                'scheduled_time_start' => Carbon::parse($request->input('scheduled_time_start')),
                'scheduled_time_end' => Carbon::parse($request->input('scheduled_time_end')),
                'is_pending' => true,
            ]);

        $service = Service::find($serviceId);
        // IDOR: o service_id vem do request; garantir que o serviço é do próprio cliente
        // autenticado antes de alterar o NIF da fatura ou materializar a aceitação (abaixo).
        if ($service && $service->customer_id !== $customerId) {
            return new ApiErrorResponse(new \Exception('Service does not belong to customer'), 'Service not found', 404);
        }
        if ($service && $request->input('nif')) {
            $service->update(['nif' => $request->input('nif')]);
        }

        if ($vendor->scheduleAvailable()->where('auto_accept', '=', true)->where('is_enabled', '=', true)->exists()) {
            $schedule->update(['is_pending' => false]);
            AcceptScheduleEvent::dispatch($customer->id, ['schedule_id' => $schedule->id, 'service_id' => $serviceId]);
            $this->acceptService->acceptSchedule($service);

            ServiceScheduledEvent::dispatch($schedule->vendor->user->id, ['id' => $schedule->id, 'service_id' => $service->id]);
            $vendor->user->notifyNow(new NewScheduledServiceNotification($schedule));
        } else {
            CreateScheduleEvent::dispatch($vendor->user->id, ['id' => $schedule->id, 'service_id' => $service->id]);
        }

        return new ApiSuccessResponse([
            'schedule_id' => $schedule->id,
            'service_id' => $serviceId,
        ]);
    }

    public function getScheduleData(Schedule $schedule): ApiSuccessResponse
    {
        if ($schedule->customer_id !== auth()->user()->id) {
            abort(403);
        }

        $scheduleData = ScheduleEventData::fromModel($schedule);

        return ApiSuccessResponse::make($scheduleData);
    }
}
