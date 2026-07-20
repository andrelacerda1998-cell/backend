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
    }
}
