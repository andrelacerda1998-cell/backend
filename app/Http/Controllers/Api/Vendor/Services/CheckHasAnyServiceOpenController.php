<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Enums\Services\ServiceStatus;
use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;

class CheckHasAnyServiceOpenController extends Controller
{
    public function __invoke()
    {
        $user = auth()->user();

        // $service = $user->vendor->services()->whereIn('status', [ServiceStatus::ACCEPTED])->get()->first();

        // return new ApiSuccessResponse(compact('service'));

        $service = $user->vendor->services()
            ->whereIn('status', [ServiceStatus::FINISHED, ServiceStatus::ACCEPTED, ServiceStatus::ARRIVED])
            //->whereDoesntHave('schedule')
            ->get()
            ->first();

        if (!$service) {
            return new ApiSuccessResponse(compact('service'));
        }

        return new ApiSuccessResponse(['service' => $service->formatDataForVendor()]);
    }

    public function service(Service $service): ApiSuccessResponse|ApiErrorResponse
    {
        try {
            // IDOR: a rota GET /vendor/services/{service} expunha formatDataForVendor() (PII do
            // cliente) de qualquer serviço por enumeração de IDs. Validar o dono como em
            // GetServiceDetailsController antes de devolver os dados.
            if ($service->vendor_id !== auth()->user()->vendor?->id) {
                throw new ServiceNotFound;
            }

            return new ApiSuccessResponse(['service' => $service->formatDataForVendor()]);
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
