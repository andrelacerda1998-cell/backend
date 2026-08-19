<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Models\Vendor;

class ListPendingServicesController extends Controller
{
    public function __invoke()
    {
        $vendor = auth()->user()->vendor;
        // serviceType e media pré-carregados: sem isto, cada serviço da lista
        // dispara as suas próprias consultas (o N+1 cresce com a fila de
        // pedidos, que é exatamente quando o técnico tem pressa).
        $openServices = $vendor->openServices()->with(['serviceType', 'media'])->get();

        $transformedServices = $openServices->transform(fn (Service $service) => $this->transformService($service, $vendor));

        return new ApiSuccessResponse(['services' => $transformedServices]);
    }

    private function transformService(Service $service, Vendor $vendor): array
    {
        $distance = $vendor->calculateDistance($service->address);
        $serviceType = $service->serviceType->name;
        $hourlyRate = $vendor->price_rate;

        return [
            'id' => $service->id,
            'status' => $service->status,
            'distance' => $distance,
            'service_type' => $serviceType,
            'value' => $hourlyRate,
            // A descrição e as fotos entram JÁ na lista, e não só no detalhe.
            // É aqui que o técnico decide se aceita, e um pedido tem tempo de
            // vida curto: mandá-lo abrir outro ecrã para perceber o que é
            // significa decidir às cegas ou perder o pedido a investigar.
            'quantity' => (int) ($service->quantity ?? 1),
            'customer_notes' => $service->customer_notes,
            'customer_photos' => $service->customerPhotosPayload(),
            'created_at' => $service->created_at,
        ];
    }
}
