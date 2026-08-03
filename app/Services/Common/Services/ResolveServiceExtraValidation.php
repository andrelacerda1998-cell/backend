<?php

namespace App\Services\Common\Services;

use App\Models\Service;
use App\Models\ServiceExtra;
use RwInteractive\PayshopSdk\Api\Payments\PaymentOrder as PaymentOrderApi;

/**
 * Callback 3DS de um extra (tempo/peças), disparado pelo Payshop a redirecionar o browser
 * do cliente de volta para /payshop/success|failure/{service}?extra={id}&signature=...
 *
 * Espelha o desfecho do serviço base (Validation\SuccessController/FailureValidationController)
 * mas opera sobre a ORDEM DEDICADA do extra (ServiceExtra->paymentOrder), nunca sobre a
 * ordem do serviço base — o serviço já está pago nesta altura (só há extras com o serviço
 * a decorrer), por isso reutilizar o fluxo do serviço base abortaria com "already paid".
 */
class ResolveServiceExtraValidation
{
    public function __construct(private readonly NotifyServiceExtraChargeFailed $notifyChargeFailed) {}

    /** @return string Path do deep link para a app (sem esquema/host). */
    public function success(Service $service, int $extraId): string
    {
        $extra = $service->extras()->find($extraId);

        if (! $extra) {
            abort(404, 'Extra not found');
        }

        if ($extra->isCharged()) {
            // Callback duplicado (ex.: o browser reenviou o pedido) — idempotente.
            return $this->path('extra-success', $service, $extra);
        }

        $paymentDetails = PaymentOrderApi::make()->details($extra->paymentOrder);
        $successStatuses = ['SUCCESS', 'PENDING_CONFIRMATION', 'PAID'];

        if (! in_array($paymentDetails['order']['status'] ?? null, $successStatuses, true)) {
            // Pré-autorização ainda a processar quando o browser regressou — devolve o
            // controlo à app; ela cai num ecrã de espera e volta a consultar depois.
            return $this->path('extra-pending', $service, $extra);
        }

        \DB::transaction(function () use ($extra) {
            $locked = ServiceExtra::whereKey($extra->getKey())->lockForUpdate()->first();
            if (! $locked || $locked->isCharged()) {
                return; // já resolvido por um callback concorrente
            }
            $locked->forceFill(['payment_status' => 'paid', 'charged_at' => now(), 'payment_error' => null])->save();
        });

        return $this->path('extra-success', $service, $extra);
    }

    public function failure(Service $service, int $extraId): string
    {
        $extra = $service->extras()->find($extraId);

        if ($extra && ! $extra->isCharged()) {
            $extra->forceFill(['payment_status' => 'failed', 'payment_error' => '3ds_declined'])->save();
            $this->notifyChargeFailed->handle($service, $extra->refresh());
        }

        return 'validation/extra-failed?service='.$service->id.'&extra='.$extraId;
    }

    private function path(string $outcome, Service $service, ServiceExtra $extra): string
    {
        return 'validation/'.$outcome.'?service='.$service->id.'&extra='.$extra->id;
    }
}
