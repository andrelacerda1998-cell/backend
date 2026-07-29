<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Enums\Services\ServiceStatus;
use App\Exceptions\Api\Common\Service\ServiceNotFound;
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

            if (!$user->isVendor()) {
                throw new WrongApp;
            }

            // Filtro do histórico: all | completed | cancelled | lost
            $filter = request('filter', 'completed');
            $statusesByFilter = [
                'completed' => [ServiceStatus::CLOSED],
                'cancelled' => [ServiceStatus::CANCELED],
                'lost' => [ServiceStatus::REFUSED],
                'all' => [ServiceStatus::CLOSED, ServiceStatus::CANCELED, ServiceStatus::REFUSED],
            ];
            $statuses = $statusesByFilter[$filter] ?? $statusesByFilter['completed'];

            $services = $user->vendor->services()->whereIn('status', $statuses);

            // Totais para o cabeçalho (independentes da paginação)
            $totals = [
                'completed_count' => $user->vendor->services()->where('status', ServiceStatus::CLOSED)->count(),
                'completed_amount' => (int) $user->vendor->services()->where('status', ServiceStatus::CLOSED)->sum('amount_for_vendor'),
                'cancelled_count' => $user->vendor->services()->where('status', ServiceStatus::CANCELED)->count(),
                'lost_count' => $user->vendor->services()->where('status', ServiceStatus::REFUSED)->count(),
                'lost_amount' => (int) $user->vendor->services()->where('status', ServiceStatus::REFUSED)->sum('amount_for_vendor'),
                // Recorte da semana corrente (segunda → agora): é o que a Home mostra no
                // cartão de Auto Aceitação. Sem isto só havia o total de sempre, que não
                // serve para dizer "esta semana perdeste X".
                'lost_week_count' => $user->vendor->services()
                    ->where('status', ServiceStatus::REFUSED)
                    ->where('updated_at', '>=', now()->startOfWeek())
                    ->count(),
                'lost_week_amount' => (int) $user->vendor->services()
                    ->where('status', ServiceStatus::REFUSED)
                    ->where('updated_at', '>=', now()->startOfWeek())
                    ->sum('amount_for_vendor'),
            ];

            $servicesLength = $services->count();
            $limit = 10;
            $offSet = request('offset') ?? 0;
            $have_more = $servicesLength > ($offSet + $limit);

            $services = $services
                // Eager-load dos media dos avatares acedidos no transform (evita N+1; resposta
                // idêntica). Paginação mantida em limit($offSet+$limit) — ver nota no controller
                // do customer / issue da paginação acoplada à app.
                ->with('customerUser', 'customerUser.media', 'serviceType.operationArea', 'vendor.user', 'vendor.user.media', 'media')
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
                        // 'amount' => $service->amount,
                        'amount_for_vendor' => $service->amount_for_vendor,
                        'customer' => $service->customerUser?->only('name', 'avatar'),
                        'vendor' => $service?->vendor?->user ? [
                            'user' => $service->vendor->user->only('name', 'phone', 'email', 'avatar'),
                            'price_rate' => $service->vendor->price_rate,
                        ] : null,
                        'service_type' => $service->serviceType ? [
                            'id' => $service->serviceType?->id,
                            'name' => $service->serviceType?->name,
                            'time' => $service->serviceType?->time,
                            'operation_area' => $service->serviceType?->operationArea?->only('id', 'name'),
                        ] : null,
                        'address' => $service->address ? [
                            'name' => $service->address['name'] ?? null,
                            'additional_info' => $service->address['additional_info'] ?? null,
                            'latitude' => $service->address['latitude'] ?? null,
                            'longitude' => $service->address['longitude'] ?? null,
                        ] : null,
                        // 'rating_by_customer' => $service->rating_by_customer,
                        'rating_by_vendor' => $service->rating_by_vendor,
                        'created_at' => $service->created_at,
                        'invoice_id' => $service->invoice_id,
                        // Temporary URL (não getFirstMediaUrl): com disco de media privado (S3) o
                        // URL permanente dá 403; o detalhe do serviço já usa temporary. Uniformizado.
                        'invoice' => $service->getFirstTemporaryUrl(now()->addMinutes(30), 'invoices'),
                    ];
                });

            return new ApiSuccessResponse(compact('services', 'have_more', 'totals'));

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
