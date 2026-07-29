<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Models\ServiceExtra;
use App\Notifications\Vendor\ServiceExtraResolvedNotification;
use Exception;
use Illuminate\Http\Request;

/**
 * Lado do CLIENTE dos extras (tempo extra / peças) pedidos pelo técnico.
 *
 * IMPORTANTE: aprovar um extra apenas muda o estado do pedido. NÃO mexe em
 * `amount`, `amount_for_vendor`, comissão nem em qualquer ordem de pagamento —
 * o comportamento financeiro do serviço fica exatamente como estava.
 */
class ServiceExtrasController extends Controller
{
    /** Só o cliente dono do serviço pode ver/responder aos extras. */
    private function authorizeService(Service $service): bool
    {
        return $service->customer_id === auth()->user()?->id;
    }

    private function present(ServiceExtra $e): array
    {
        return [
            'id' => $e->id,
            'type' => $e->type,
            'description' => $e->description,
            'minutes' => $e->minutes,
            'amount' => (int) $e->amount,
            'status' => $e->status,
            'rejection_reason' => $e->rejection_reason,
            'created_at' => $e->created_at?->toIso8601String(),
            'resolved_at' => $e->resolved_at?->toIso8601String(),
        ];
    }

    /** Extras pendentes (por omissão) ou todos com ?all=1. */
    public function index(Request $request, Service $service)
    {
        if (! $this->authorizeService($service)) {
            return new ApiErrorResponse(new Exception, 'Service not found', 404);
        }

        $query = $service->extras()->orderByDesc('created_at');

        if (! $request->boolean('all')) {
            $query->where('status', 'pending');
        }

        $extras = $query->get()->map(fn ($e) => $this->present($e));

        $pendingAmount = (int) $service->extras()->where('status', 'pending')->sum('amount');
        $approvedAmount = (int) $service->extras()->where('status', 'approved')->sum('amount');

        return new ApiSuccessResponse([
            'extras' => $extras,
            'pending_amount' => $pendingAmount,
            'approved_amount' => $approvedAmount,
        ]);
    }

    public function approve(Service $service, ServiceExtra $extra)
    {
        return $this->resolve($service, $extra, true, null);
    }

    public function reject(Request $request, Service $service, ServiceExtra $extra)
    {
        $validated = $request->validate([
            'reason' => 'nullable|string|max:150',
        ]);

        return $this->resolve($service, $extra, false, $validated['reason'] ?? null);
    }

    private function resolve(Service $service, ServiceExtra $extra, bool $approved, ?string $reason)
    {
        if (! $this->authorizeService($service) || $extra->service_id !== $service->id) {
            return new ApiErrorResponse(new Exception, 'Not found', 404);
        }

        if ($extra->status !== 'pending') {
            return new ApiErrorResponse(new Exception, 'This request was already resolved', 422);
        }

        $extra->update([
            'status' => $approved ? 'approved' : 'rejected',
            'rejection_reason' => $approved ? null : $reason,
            'resolved_at' => now(),
        ]);

        $this->notifyVendor($service, $extra->refresh(), $approved);

        return new ApiSuccessResponse(['extra' => $this->present($extra)]);
    }

    private function notifyVendor(Service $service, ServiceExtra $extra, bool $approved): void
    {
        $vendorUser = $service->vendor?->user;

        if (! $vendorUser || $vendorUser->trashed() || ! $vendorUser->devices()->exists()) {
            return;
        }

        try {
            $vendorUser->notify(new ServiceExtraResolvedNotification($service, $extra, $approved));
        } catch (\Throwable $e) {
            report($e); // falha de push nunca quebra a resposta do cliente
        }
    }
}
