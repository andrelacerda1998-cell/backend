<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Models\ServiceExtra;
use App\Notifications\Customer\ServiceExtraRequestedNotification;
use Exception;
use Illuminate\Http\Request;

/**
 * Tempo extra e peças/materiais pedidos durante o serviço.
 * Só entram no valor depois de o cliente aprovar.
 */
class ServiceExtrasController extends Controller
{
    private function authorizeService(Service $service): bool
    {
        return $service->vendor_id === auth()->user()->vendor?->id;
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

    public function index(Service $service)
    {
        if (! $this->authorizeService($service)) {
            return new ApiErrorResponse(new Exception, 'Service not found', 404);
        }

        $extras = $service->extras()->orderByDesc('created_at')->get()->map(fn ($e) => $this->present($e));

        // Total já aprovado (entra no valor a receber)
        $approved = $service->extras()->where('status', 'approved')->sum('amount');
        $approvedMinutes = $service->extras()->where('status', 'approved')->where('type', 'time')->sum('minutes');

        return new ApiSuccessResponse([
            'extras' => $extras,
            'approved_amount' => (int) $approved,
            'approved_minutes' => (int) $approvedMinutes,
        ]);
    }

    public function store(Request $request, Service $service)
    {
        if (! $this->authorizeService($service)) {
            return new ApiErrorResponse(new Exception, 'Service not found', 404);
        }

        // Só durante a execução (o técnico já chegou ao local).
        if ($service->status !== ServiceStatus::ARRIVED) {
            return new ApiErrorResponse(new Exception, 'Service is not in execution', 422);
        }

        $validated = $request->validate([
            'type' => 'required|in:time,part',
            'minutes' => 'required_if:type,time|nullable|integer|min:5|max:480',
            'description' => 'required_if:type,part|nullable|string|max:150',
            'amount' => 'required_if:type,part|nullable|integer|min:0',
        ]);

        // Tempo extra: valor = price_rate (€/h em cêntimos) proporcional aos minutos.
        $amount = (int) ($validated['amount'] ?? 0);
        if ($validated['type'] === 'time') {
            $hourlyCents = (int) round(((float) ($service->vendor?->price_rate ?? 0)) * 100);
            $amount = (int) round($hourlyCents * ($validated['minutes'] / 60));
        }

        $extra = $service->extras()->create([
            'type' => $validated['type'],
            'description' => $validated['description'] ?? null,
            'minutes' => $validated['minutes'] ?? null,
            'amount' => $amount,
            'status' => 'pending',
        ]);

        $this->notifyCustomer($service, $extra);

        return new ApiSuccessResponse(['extra' => $this->present($extra)]);
    }

    /** Avisar o cliente de que há um pedido à espera de resposta. */
    private function notifyCustomer(Service $service, ServiceExtra $extra): void
    {
        $customer = $service->customer;

        if (! $customer) {
            return;
        }

        try {
            $customer->notify(new ServiceExtraRequestedNotification($service, $extra));
        } catch (\Throwable $e) {
            report($e); // falha de push nunca quebra o registo do extra
        }
    }

    /** Retirar um pedido ainda por responder. */
    public function destroy(Service $service, ServiceExtra $extra)
    {
        if (! $this->authorizeService($service) || $extra->service_id !== $service->id) {
            return new ApiErrorResponse(new Exception, 'Not found', 404);
        }

        if ($extra->status !== 'pending') {
            return new ApiErrorResponse(new Exception, 'Only pending requests can be withdrawn', 422);
        }

        $extra->update(['status' => 'withdrawn', 'resolved_at' => now()]);

        return new ApiSuccessResponse(['extra' => $this->present($extra)]);
    }
}
