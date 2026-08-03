<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Models\ServiceExtra;
use App\Notifications\Vendor\ServiceExtraResolvedNotification;
use App\Services\Common\Services\ChargeServiceExtra;
use App\Services\Common\Services\NotifyServiceExtraChargeFailed;
use Exception;
use Illuminate\Http\Request;

/**
 * Lado do CLIENTE dos extras (tempo extra / peças) pedidos pelo técnico.
 *
 * Aprovar um extra COBRA o valor ao cliente numa ordem Payshop dedicada
 * (ChargeServiceExtra), reutilizando o método de pagamento do serviço base.
 * O serviço base nunca é tocado: `amount`, `amount_for_vendor`, comissão e a
 * ordem de pagamento original ficam exatamente como estavam. O crédito do
 * extra ao técnico acontece no fecho (CloseService), só se a cobrança tiver
 * sido efetivamente garantida.
 */
class ServiceExtrasController extends Controller
{
    public function __construct(
        private readonly NotifyServiceExtraChargeFailed $notifyChargeFailed,
    ) {}

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
            'payment_status' => $e->payment_status,
            // 'no_stored_payment_method' | '3ds_required' | mensagem curta de erro da captura —
            // a app usa isto para decidir se mostra "adicionar cartão" ou "confirmar pagamento".
            'payment_error' => $e->payment_error,
            // Só populado quando payment_status === 'requires_action' por 3DS (não por falta
            // de cartão — nesse caso não há ordem nenhuma, logo não há URL).
            'payment_validation_url' => $e->payment_validation_url,
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

        // Transição pending→resolved sob lock: duas aprovações concorrentes (double-tap,
        // retry de rede) nunca passam as duas o guard — só a primeira resolve e cobra.
        $transitioned = \DB::transaction(function () use ($extra, $approved, $reason): bool {
            $locked = ServiceExtra::whereKey($extra->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->status !== 'pending') {
                return false;
            }

            $locked->update([
                'status' => $approved ? 'approved' : 'rejected',
                'rejection_reason' => $approved ? null : $reason,
                'resolved_at' => now(),
            ]);

            return true;
        });

        if (! $transitioned) {
            return new ApiErrorResponse(new Exception, 'This request was already resolved', 422);
        }

        $extra->refresh();

        // Cobrança FORA da transação (chamada HTTP externa). Idempotente: ChargeServiceExtra
        // só cobra quando ainda não há ordem/estado, e payment_order_id é UNIQUE na BD.
        if ($approved) {
            $chargeResult = app(ChargeServiceExtra::class)->charge($service, $extra);

            if ($chargeResult === 'failed') {
                $this->notifyChargeFailed->handle($service, $extra->refresh());
            }
        }

        $this->notifyVendor($service, $extra->refresh(), $approved);

        return new ApiSuccessResponse(['extra' => $this->present($extra)]);
    }

    /**
     * Repetir a cobrança de um extra aprovado que ficou sem forma de cobrar por falta de
     * método de pagamento gravado (payment_error === 'no_stored_payment_method') — ex.: o
     * cliente acabou de adicionar um cartão na app. ChargeServiceExtra só avança quando
     * ainda não há payment_order_id; qualquer outro estado (3DS pendente, MBWay a aguardar,
     * já cobrado) devolve o estado atual sem tentar nada — essa é a garantia de nunca criar
     * uma segunda ordem para o mesmo extra.
     */
    public function retryCharge(Service $service, ServiceExtra $extra)
    {
        if (! $this->authorizeService($service) || $extra->service_id !== $service->id) {
            return new ApiErrorResponse(new Exception, 'Not found', 404);
        }

        $eligible = \DB::transaction(function () use ($extra): bool {
            $locked = ServiceExtra::whereKey($extra->getKey())->lockForUpdate()->first();

            return (bool) ($locked && $locked->status === 'approved' && ! $locked->isCharged() && $locked->payment_order_id === null);
        });

        if (! $eligible) {
            return new ApiErrorResponse(new Exception, 'Nothing to retry for this extra', 422);
        }

        $chargeResult = app(ChargeServiceExtra::class)->charge($service, $extra->refresh());

        if ($chargeResult === 'failed') {
            $this->notifyChargeFailed->handle($service, $extra->refresh());
        }

        return new ApiSuccessResponse(['extra' => $this->present($extra->refresh())]);
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
