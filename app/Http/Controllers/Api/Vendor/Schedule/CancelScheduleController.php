<?php

namespace App\Http\Controllers\Api\Vendor\Schedule;

use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Schedule\Schedule;
use App\Notifications\Customer\ScheduleCanceledByVendorNotification;
use App\Services\Common\Services\CancelService;
use Exception;

class CancelScheduleController extends Controller
{
    public function __invoke(Schedule $schedule)
    {
        try {
            $vendor = auth()->user()->vendor;

            if (! $vendor || $schedule->vendor_id !== $vendor->id) {
                throw new Exception('Schedule not found', 404);
            }

            $service = $schedule->service;

            if ($service) {
                $cancelService = new CancelService($service);

                if ($service->status === ServiceStatus::PENDING || $service->status === ServiceStatus::SCHEDULED) {
                    $cancelService->vendorCancelService();
                }
            }

            // Notificar o customer, e só se houver destinatário com device. Falha de push
            // NUNCA pode quebrar o cancelamento. A notificação é construída antes do delete()
            // (ela captura os dados do schedule no construtor).
            $customer = $schedule->customer;
            if ($customer && ! $customer->trashed() && $customer->devices()->exists()) {
                try {
                    $customer->notify(new ScheduleCanceledByVendorNotification($schedule));
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            $schedule->delete();

            return new ApiSuccessResponse;
        } catch (Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
