<?php

namespace App\Services\Common\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use Bavix\Wallet\Internal\Exceptions\ExceptionInterface;
use Illuminate\Support\Facades\Log;
use RwInteractive\PayshopSdk\Enums\Payment\Status;

class CloseService
{
    public function __construct(private Service $service)
    {
    }

    /**
     * The customer confirmed the service. Capture the payment and, only if the money is actually
     * secured, close the service and pay the vendor + platform commission. If the capture does not
     * succeed, the service is parked in CLOSED_PENDING_PAYMENT (vendor NOT paid) so it is neither
     * stuck nor overpaid — it can be retried manually from the backoffice via retryCapture().
     *
     * @return ServiceStatus CLOSED when settled, CLOSED_PENDING_PAYMENT when the capture failed.
     *
     * @throws ExceptionInterface
     * @throws \Throwable
     */
    public function close(): ServiceStatus
    {
        return \DB::transaction(function (): ServiceStatus {
            // Lock the row so two concurrent closes cannot both pass the FINISHED guard and pay the
            // vendor twice. The second request blocks here, then sees the new status and throws 409.
            $locked = Service::whereKey($this->service->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->status !== ServiceStatus::FINISHED) {
                throw new \Exception('Service already closed', 409);
            }

            // Reload the working instance with the authoritative state held under the lock.
            $this->service->refresh();

            if (! $this->capturePayment()) {
                // Payment not captured — never pay the vendor from money we did not collect. Park the
                // service for a manual capture retry instead of leaving it stuck as FINISHED.
                $this->service->status = ServiceStatus::CLOSED_PENDING_PAYMENT;
                $this->service->save();

                return ServiceStatus::CLOSED_PENDING_PAYMENT;
            }

            $this->service->status = ServiceStatus::CLOSED;
            $this->service->save();

            $this->settle();

            return ServiceStatus::CLOSED;
        });
    }

    /**
     * Retry the capture for a service parked in CLOSED_PENDING_PAYMENT (backoffice action). On a
     * successful capture the service is closed and the vendor is paid; otherwise it stays parked.
     *
     * @throws ExceptionInterface
     * @throws \Throwable
     */
    public function retryCapture(): ServiceStatus
    {
        return \DB::transaction(function (): ServiceStatus {
            $locked = Service::whereKey($this->service->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->status !== ServiceStatus::CLOSED_PENDING_PAYMENT) {
                throw new \Exception('Service is not awaiting payment capture', 409);
            }

            $this->service->refresh();

            if (! $this->capturePayment()) {
                throw new \Exception('Payment capture failed');
            }

            $this->service->status = ServiceStatus::CLOSED;
            $this->service->save();

            $this->settle();

            return ServiceStatus::CLOSED;
        });
    }

    /**
     * Terminal escape for a service stuck in CLOSED_PENDING_PAYMENT whose capture keeps failing
     * (backoffice action). Moves the service to CANCELED — which the ServiceObserver already handles
     * by refunding credit_used and cancelling/refunding the pre-authorization — WITHOUT paying the
     * vendor. Without this, credit withdrawn at open time stays locked forever.
     *
     * @throws \Throwable
     */
    public function abandonAndRefund(): ServiceStatus
    {
        return \DB::transaction(function (): ServiceStatus {
            // Same lock/guard shape as retryCapture(), opposite outcome.
            $locked = Service::whereKey($this->service->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->status !== ServiceStatus::CLOSED_PENDING_PAYMENT) {
                throw new \Exception('Service is not awaiting payment capture', 409);
            }

            $this->service->refresh();

            // CANCELED aciona ServiceObserver::updating → reembolsa credit_used + cancela/reembolsa a
            // pré-autorização. O vendor NÃO é pago (settle() nunca corre neste caminho).
            $this->service->status = ServiceStatus::CANCELED;
            $this->service->save();

            return ServiceStatus::CANCELED;
        });
    }

    /**
     * Attempt to secure the customer's payment. Returns true only when the money is actually
     * captured: a card paymentOrder that reaches SUCCESS after confirm(), or a service with no
     * card paymentOrder that is already fully PAID (e.g. wallet-credit funded).
     */
    private function capturePayment(): bool
    {
        $paymentOrder = $this->service->paymentOrder;

        if (! $paymentOrder) {
            return $this->service->payment_status === PaymentStatus::PAID;
        }

        // Idempotência: se a order já foi capturada com sucesso, não voltar a confirmar
        // (evita dupla captura quando close()/retryCapture() correm mais do que uma vez na
        // reconciliação). Não bloqueia a PRIMEIRA captura — aí o status ainda não é SUCCESS.
        if ($paymentOrder->status === Status::SUCCESS) {
            return true;
        }

        try {
            $paymentOrder->confirm();
        } catch (\Exception $e) {
            Log::warning($e);

            return false;
        }

        return $paymentOrder->status === Status::SUCCESS;
    }

    /**
     * Pay the vendor and the platform commission for a captured service.
     *
     * @throws ExceptionInterface
     */
    private function settle(): void
    {
        $vendor = $this->service->vendor;

        $vendor->user->deposit($this->service->amount_for_vendor, $this->service->getMetaProduct());

        $vendorFee = abs($this->service->getRawOriginal('amount_for_vendor'));
        $systemFee = abs($this->service->getRawOriginal('amount') - $vendorFee);
        system_wallet()->deposit($systemFee, $this->service->getMetaProduct());

        // Extras aprovados: capturar os que ficaram pendentes (MBWay) e creditar o técnico
        // pelos que estão efetivamente cobrados. O crédito do serviço base acima nunca muda.
        $this->settleExtras();
    }

    /**
     * Extras aprovados pelo cliente:
     *  1) MBWay `pending_confirmation`: consultar a ordem e capturar (confirm) se o cliente
     *     aceitou o push; senão marcar `failed` e avisar técnico + cliente.
     *  2) Cada extra efetivamente cobrado (`paid`/`not_required`) e ainda não creditado
     *     (`vendor_credited_at` null — guard de idempotência) é depositado agora.
     *
     * Regra de comissão: não existe nenhuma regra de comissão para extras no código atual.
     * DECISÃO A VALIDAR: aplica-se a MESMA proporção do serviço base
     * (amount_for_vendor / amount); o resto vai para a carteira da plataforma. Se o dono do
     * produto decidir que o extra é 100% do técnico, basta trocar o rácio por 1.0 aqui.
     */
    private function settleExtras(): void
    {
        $extras = $this->service->extras()->where('status', 'approved')->lockForUpdate()->get();

        if ($extras->isEmpty()) {
            return;
        }

        $baseAmount = abs((int) $this->service->getRawOriginal('amount'));
        $baseVendor = abs((int) $this->service->getRawOriginal('amount_for_vendor'));
        $ratio = $baseAmount > 0 ? $baseVendor / $baseAmount : 1.0;

        foreach ($extras as $extra) {
            // MBWay ficou à espera do push na aprovação — tentar capturar agora.
            if ($extra->payment_status === 'pending_confirmation' && $extra->paymentOrder) {
                $this->captureExtraOrder($extra);
            }

            if (! $extra->isCharged() || $extra->vendor_credited_at !== null) {
                continue; // não cobrado (nunca pagar dinheiro que não entrou) ou já creditado
            }

            $extraVendor = (int) round(((int) $extra->amount) * $ratio);
            $extraSystem = ((int) $extra->amount) - $extraVendor;

            $this->service->vendor->user->deposit($extraVendor, $this->extraMeta($extra));
            if ($extraSystem > 0) {
                system_wallet()->deposit($extraSystem, $this->extraMeta($extra));
            }

            $extra->forceFill(['vendor_credited_at' => now()])->save();
        }
    }

    /** Captura da ordem MBWay de um extra no fecho; falha => `failed` + notificação. */
    private function captureExtraOrder(\App\Models\ServiceExtra $extra): void
    {
        try {
            $order = $extra->paymentOrder;
            $order->updateData();

            if ($order->status === Status::PENDING_CONFIRMATION) {
                $order->confirm();
            }

            if ($order->status === Status::SUCCESS) {
                $extra->forceFill(['payment_status' => 'paid', 'charged_at' => now(), 'payment_error' => null])->save();

                return;
            }

            $extra->forceFill(['payment_status' => 'failed', 'payment_error' => 'close_capture_status_'.($order->status->value ?? 'unknown')])->save();
        } catch (\Throwable $e) {
            Log::warning('Service extra capture at close failed', ['extra_id' => $extra->id, 'error' => $e->getMessage()]);
            $extra->forceFill(['payment_status' => 'failed', 'payment_error' => mb_substr($e->getMessage(), 0, 255)])->save();
        }

        $this->notifyExtraChargeFailed($extra);
    }

    private function notifyExtraChargeFailed(\App\Models\ServiceExtra $extra): void
    {
        try {
            $vendorUser = $this->service->vendor?->user;
            if ($vendorUser && ! $vendorUser->trashed() && $vendorUser->devices()->exists()) {
                $vendorUser->notify(new \App\Notifications\Vendor\ServiceExtraChargeFailedNotification($this->service, $extra));
            }
            $this->service->customer?->notify(new \App\Notifications\Customer\ServiceExtraChargeFailedNotification($this->service, $extra));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Meta auditável do movimento de carteira do extra (distinto do serviço base). */
    private function extraMeta(\App\Models\ServiceExtra $extra): array
    {
        $meta = $this->service->getMetaProduct();
        $meta['description'] = ($meta['description'] ?? '').' — extra #'.$extra->id.' ('.$extra->type.')';
        $meta['extra_id'] = $extra->id;

        return $meta;
    }
}
