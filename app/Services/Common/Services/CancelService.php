<?php

namespace App\Services\Common\Services;

use App\Enums\Services\ServiceStatus;
use App\Jobs\Services\CreateCancellationInvoiceJob;
use App\Jobs\Services\CreateVendorCancellationInvoiceJob;
use App\Models\Service;
use Bavix\Wallet\Internal\Exceptions\ExceptionInterface;

class CancelService
{
    public function __construct(private Service $service) {}

    /**
     * @throws ExceptionInterface
     * @throws \Exception
     * @throws \Throwable
     */
    public function customerCancel(): void
    {
        $this->service->refresh();

        if ($this->service->status === ServiceStatus::PENDING
            || $this->service->status === ServiceStatus::SCHEDULED) {
            \DB::beginTransaction();
            try {
                $this->service->status = ServiceStatus::CANCELED;
                $this->service->status_justification = 'internal/services.cancel.description';
                $this->service->save();

                \DB::commit();
            } catch (\Exception $e) {
                \DB::rollBack();
                report($e);
                throw $e;
            }
        }
    }

    /**
     * Cancelamento pelo cliente ANTES de o pagamento MBWay ser confirmado. Usa um status
     * terminal próprio (CANCELED_MBWAY) porque o vendor nunca foi notificado deste serviço —
     * o controller não envia notificação de cancelamento neste caso. O save dispara o
     * ServiceObserver, que liberta/reembolsa qualquer cativação.
     *
     * @throws \Exception
     * @throws \Throwable
     */
    public function customerCancelBeforePayment(): void
    {
        $this->service->refresh();

        if ($this->service->status !== ServiceStatus::PENDING) {
            return;
        }

        \DB::beginTransaction();
        try {
            $this->service->status = ServiceStatus::CANCELED_MBWAY;
            $this->service->status_justification = 'internal/services.mbway.canceled';
            $this->service->pending_schedule_data = null;
            $this->service->save();

            \DB::commit();
        } catch (\Exception $e) {
            \DB::rollBack();
            report($e);
            throw $e;
        }
    }

    /**
     * @throws ExceptionInterface
     * @throws \Exception
     */
    public function cancelOpenService(): void
    {
        $this->service->refresh();

        // ARRIVED também é cancelável: nada foi capturado ainda (só cativo/autorização) e o
        // ServiceObserver liberta/reembolsa automaticamente ao gravar CANCELED.
        if (in_array($this->service->status, [ServiceStatus::ACCEPTED, ServiceStatus::ARRIVED], true)) {
            \DB::beginTransaction();
            try {
                $this->service->status = ServiceStatus::CANCELED;
                $this->service->status_justification = 'internal/services.cancel.description';
                $this->service->save();

                \DB::commit();
            } catch (\Exception $e) {
                \DB::rollBack();
                report($e);
                throw $e;
            }
        }
    }

    /**
     * @throws ExceptionInterface
     * @throws \Exception
     */
    public function vendorCancelService(): void
    {
        $this->service->refresh();

        // Cancelamento de serviço agendado/pendente pelo vendor — espelha customerCancel().
        // Gravar CANCELED dispara o ServiceObserver, que liberta o cativo (cancel) ou
        // reembolsa (refund) automaticamente conforme o estado do paymentOrder. Sem taxa.
        if ($this->service->status === ServiceStatus::PENDING
            || $this->service->status === ServiceStatus::SCHEDULED) {
            \DB::beginTransaction();
            try {
                $this->service->status = ServiceStatus::CANCELED;
                $this->service->status_justification = 'internal/services.cancel.description';
                $this->service->save();

                \DB::commit();
            } catch (\Exception $e) {
                \DB::rollBack();
                report($e);
                throw $e;
            }

            return;
        }

        if ($this->service->status === ServiceStatus::ACCEPTED) {
            \DB::beginTransaction();
            try {
                $this->service->status = ServiceStatus::CANCELED;
                $this->service->status_justification = 'internal/services.cancel.description';
                $this->service->save();

                $vendor = $this->service->vendor;
                $cancellationFee = 0; // Temporary remove cancellation fee

                // Skip the transfer when there is no fee: some bavix versions reject a zero-amount
                // transfer, which would roll back the whole cancellation and trap the vendor.
                if ($cancellationFee > 0) {
                    $vendor->user->wallet->forceTransfer(system_wallet(), $cancellationFee, [
                        ...$this->service->getMetaProduct(),
                        'type' => 'internal/services.cancel.fee',
                    ]);
                }

                \DB::commit();
                CreateVendorCancellationInvoiceJob::dispatch($this->service);

            } catch (\Exception $e) {
                \DB::rollBack();
                throw $e;
            }
        }
    }

    /**
     * Cancelamento + reembolso de um serviço já FECHADO (CLOSED), exclusivo do superadmin.
     *
     * Num serviço fechado o pagamento já foi capturado e os valores já foram depositados
     * (prestador + comissão da plataforma) em CloseService::close(). Este método reverte
     * esses depósitos e, ao gravar CANCELED, dispara o ServiceObserver que trata o
     * reembolso do cliente (cartão via paymentOrder->refund() + crédito) e define
     * payment_status = REFUNDED.
     *
     * @throws ExceptionInterface
     * @throws \Exception
     * @throws \Throwable
     */
    public function superAdminCancelClosedService(string $justification): void
    {
        abort_unless(auth()->user()?->hasRole('super-admin') ?? false, 403);

        $this->service->refresh();

        if ($this->service->status !== ServiceStatus::CLOSED) {
            throw new \Exception('Only closed services can be cancelled and refunded here.');
        }

        \DB::beginTransaction();
        try {
            // Reverter os depósitos feitos no fecho (espelha CloseService::close()).
            $vendorFee = abs($this->service->getRawOriginal('amount_for_vendor'));
            $systemFee = abs($this->service->getRawOriginal('amount') - $vendorFee);

            $reversalMeta = [
                ...$this->service->getMetaProduct(),
                'type' => 'internal/services.refunds.reversal',
            ];

            // forceWithdraw permite saldo negativo do prestador para garantir o reembolso.
            $this->service->vendor->user->forceWithdraw($vendorFee, $reversalMeta);
            system_wallet()->forceWithdraw($systemFee, $reversalMeta);

            // Gravar CANCELED dispara o ServiceObserver: reembolsa o cliente (cartão + crédito)
            // e define payment_status = REFUNDED.
            $this->service->status = ServiceStatus::CANCELED;
            $this->service->status_justification = $justification;
            $this->service->save();

            \DB::commit();

            CreateCancellationInvoiceJob::dispatch($this->service);
        } catch (\Exception $e) {
            \DB::rollBack();
            report($e);
            throw $e;
        }
    }
}
