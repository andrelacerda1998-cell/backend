<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Exceptions\Api\Common\Service\ServiceNotFound;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;

class GetServiceDetailsController extends Controller
{
    public function __invoke(Service $service)
    {
        try {
            if ($service->vendor_id !== auth()->user()->vendor->id) {
                throw new ServiceNotFound;
            }

            $service = $service->load('customer', 'serviceType', 'vendor');

            // Usa o formatador comum em vez de montar um payload à parte: este
            // ecrã precisa da MORADA e do AGENDAMENTO (dia/hora) para o técnico
            // saber onde e quando é o serviço, e a versão anterior não os
            // enviava — daí aparecerem vazios na app.
            $data = $service->formatDataForVendor();

            // Explícito, para não depender da convenção de `amount`: o que o
            // técnico recebe é a sua parte, já sem a comissão da Piquet.
            $data['amount_for_vendor'] = $service->amount_for_vendor;

            // `scheduled_at` é só o DIA. A hora vive no agendamento e é o que o
            // técnico precisa de ver para saber quando executar o serviço.
            $data['schedule'] = $service->schedule ? [
                'scheduled_day' => $service->schedule->scheduled_day,
                'scheduled_time' => [
                    'start' => $service->schedule->scheduled_time_start,
                    'end' => $service->schedule->scheduled_time_end,
                ],
            ] : null;

            return new ApiSuccessResponse([
                'service' => $data,
            ]);
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
