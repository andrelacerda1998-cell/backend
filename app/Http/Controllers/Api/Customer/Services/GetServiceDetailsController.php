<?php

namespace App\Http\Controllers\Api\Customer\Services;

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
            if ($service->customer_id !== auth()->user()->id) {
                throw new ServiceNotFound;
            }

            $service = $service->load('customerUser', 'serviceType', 'vendor', 'media');

            $data = [
                'id' => $service->id,
                'status' => $service->status,
                'distance' => $service->distance,
                'customer_notes' => $service->customer_notes,
                'vendor_notes' => $service->vendor_notes,
                'amount' => $service->amount,
                'customer' => $service->customerUser?->only('name', 'phone', 'email'),
                'vendor' => $service->vendor ? [
                    'user' => $service->vendor->user?->only('name', 'phone', 'email'),
                    'price_rate' => $service->vendor->price_rate,
                    'location' => $service->vendor->currentLocation?->only('latitude', 'longitude'),
                    // O escudo "Técnico Verificado" era texto fixo no ecrã do
                    // cliente, sem consultar campo nenhum — e não podia
                    // consultar, porque não havia campo nenhum para consultar.
                    // Passa a vir do mesmo `can_accept_service` que decide
                    // quem é convidado: documentos válidos, IBAN, AT e
                    // workspace. Se o portão cair, o selo cai com ele.
                    'is_verified' => (bool) $service->vendor->can_accept_service,
                ] : null,
                'service_type' => $service->serviceType ? [
                    'id' => $service->serviceType->id,
                    'name' => $service->serviceType->name,
                    'time' => $service->serviceType->time,
                    'operation_area' => $service->serviceType->operationArea?->only('id', 'name'),
                ] : null,
                'address' => $service->address ? [
                    'name' => $service->address['name'],
                    'additional_info' => $service->address['additional_info'],
                    'latitude' => $service->address['latitude'],
                    'longitude' => $service->address['longitude'],
                ] : null,
                // Sem estes dois, o ecrã de acompanhamento não tinha como saber
                // se o técnico já saiu: decidia o texto só pelo estado e
                // escrevia "está a caminho" três segundos depois do pagamento,
                // quando ele ainda nem sabia que fora escolhido. Os componentes
                // que já liam `on_the_way_at` recebiam-no vazio.
                'on_the_way_at' => $service->on_the_way_at,
                'arrived_at' => $service->arrived_at,
                'rating_by_customer' => $service->rating_by_customer,
                'created_at' => $service->created_at,
                'updated_at' => $service->updated_at,
                'invoice' => $service->getFirstTemporaryUrl(now()->addMinutes(30), 'invoices'),
                'server_time' => now()->toIso8601String(),
                'created_timestamp' => $service->created_at->timestamp,
                'updated_timestamp' => $service->updated_at->timestamp,
            ];

            return new ApiSuccessResponse([
                'service' => $data,
            ]);
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
