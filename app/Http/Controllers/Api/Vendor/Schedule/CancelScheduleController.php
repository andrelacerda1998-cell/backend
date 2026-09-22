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
                // `ApiErrorResponse` le o codigo de `getStatus()`, nunca de
                // `getCode()`: uma `Exception` crua com 404 no construtor caia
                // no 500 por omissao, e quem tentasse cancelar a marcacao de
                // outra pessoa recebia "Something went wrong" em vez de 404.
                // Mesmo formato que o ConfirmScheduleAttendanceController.
                return new ApiErrorResponse(new Exception, 'Schedule not found', 404);
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
