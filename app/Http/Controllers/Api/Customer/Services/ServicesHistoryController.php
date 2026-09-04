<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Enums\Services\ServiceStatus;
use App\Exceptions\Api\User\WrongApp;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;

class ServicesHistoryController extends Controller
{
    public function __invoke()
    {

        try {
            $user = auth()->user();

            if (! $user->isCustomer()) {
                throw new WrongApp;
            }

            // Base do histórico: fechados + cancelados, com o mesmo constraint de
            // operationArea usado na listagem para os totais baterem certo com o visível.
            $base = $user->services()
                ->whereIn('status', [ServiceStatus::CLOSED, ServiceStatus::CANCELED])
                ->whereHas('serviceType', function ($query) {
                    $query->whereHas('operationArea');
                });

            // Totais por status (independentes do filtro/paginação) para os cards e chips da app.
            $closed_count = (clone $base)->where('status', ServiceStatus::CLOSED)->count();
            $canceled_count = (clone $base)->where('status', ServiceStatus::CANCELED)->count();

            // Filtro opcional: a app pede a lista já filtrada por status.
            $services = match (request('status')) {
                'closed' => (clone $base)->where('status', ServiceStatus::CLOSED),
                'canceled' => (clone $base)->where('status', ServiceStatus::CANCELED),
                default => (clone $base),
            };

            $servicesLength = $services->count();
            $limit = 10;
            $offSet = request('offset') ?? 0;
            $have_more = $servicesLength > ($offSet + $limit);

            $services = $services
                // Eager-load das relações acedidas no transform (evita N+1; resposta idêntica).
                // Paginação mantida em limit($offSet+$limit) de propósito — a app faz replace da
                // lista com offset=length (cumulativo); mudar para offset() partiria os apps em prod.
                ->with('serviceType.operationArea', 'vendor.user', 'vendor.user.media', 'customer', 'customer.media', 'media')
                ->orderByDesc('updated_at')
                ->limit($offSet + $limit)
                ->get();

            $services = $services
                ->transform(function ($service) {
                    return [
                        'id' => $service->id,
                        'status' => $service->status,
                        'distance' => $service->distance,
                        'customer_notes' => $service->customer_notes,
                        'vendor_notes' => $service->vendor_notes,
                        'amount' => $service->amount,
                        'customer' => $service->customer?->only('name', 'phone', 'email', 'avatar'),
                        'vendor' => $service?->vendor?->user ? [
                            'user' => $service->vendor->user->only('name', 'avatar'),
                            // 'price_rate' => $service->vendor->price_rate,
                        ] : null,
                        'service_type' => $service->serviceType ? [
                            'id' => $service?->serviceType?->id,
                            'name' => $service?->serviceType?->name,
                            'time' => $service?->serviceType?->time,
                            'operation_area' => $service?->serviceType?->operationArea?->only('id', 'name'),
                        ] : null,
                        'address' => $service->address ? [
                            'name' => $service->address['name'] ?? null,
                            'additional_info' => $service->address['additional_info'] ?? null,
                            'latitude' => $service->address['latitude'] ?? null,
                            'longitude' => $service->address['longitude'] ?? null,
                        ] : null,
                        // Um cancelamento tardio COBRA 100% (CancellationPolicy); um
                        // cancelamento a tempo é reembolsado. Sem isto a app não
                        // consegue distinguir os dois e mostrava o valor como pago
                        // em ambos.
                        'payment_status' => $service->payment_status,
                        'rating_by_customer' => $service->rating_by_customer,
                        'created_at' => $service->created_at,
                        'invoice_id' => $service->invoice_id,
                        // Temporary URL (não getFirstMediaUrl): com disco de media privado (S3) o
                        // URL permanente dá 403; o detalhe do serviço já usa temporary. Uniformizado.
                        'invoice' => $service->getFirstTemporaryUrl(now()->addMinutes(30), 'invoices'),
                    ];
                });

            return new ApiSuccessResponse(compact('services', 'have_more', 'closed_count', 'canceled_count'));

        } catch (\Throwable $e) {
            return new ApiErrorResponse($e);
        }

        // try {
        //     if ($service->customer_id !== auth()->user()->id) {
        //         throw new ServiceNotFound;
        //     }

        //     $service = $service->load('customer', 'serviceType', 'vendor');

        //     $data = [
        //         'status' => $service->status,
        //         'distance' => $service->distance,
        //         'customer_notes' => $service->customer_notes,
        //         'vendor_notes' => $service->vendor_notes,
        //         'amount' => $service->amount,
        //         'customer' => $service->customer->only('name', 'phone', 'email'),
        //         'vendor' => [
        //             'user' => $service->vendor->user->only('name', 'phone', 'email'),
        //             'price_rate' => $service->vendor->price_rate,
        //         ],
        //         'service_type' => [
        //             'id' => $service->serviceType->id,
        //             'name' => $service->serviceType->name,
        //             'time' => $service->serviceType->time,
        //             'description' => $service->serviceType->description,
        //             'operation_area' => $service->serviceType->operationArea->only('id', 'name'),
        //         ],
        //         'address' => $service->address->only('id', 'name', 'latitude', 'longitude'),
        //         'rating_by_customer' => $service->rating_by_customer,
        //     ];

        //     return new ApiSuccessResponse([
        //         'service' => $data,
        //     ]);
        // } catch (\Exception $e) {
        //     return new ApiErrorResponse($e);
        // }
    }
}
