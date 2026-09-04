<?php

namespace App\Services\Common\Services;

use App\Enums\Services\ServiceStatus;
use App\Jobs\Services\CreateCancellationInvoiceJob;
use App\Jobs\Services\CreateVendorCancellationInvoiceJob;
use App\Models\Service;
use Bavix\Wallet\Internal\Exceptions\ExceptionInterface;
use RwInteractive\PayshopSdk\Enums\Payment\Status;

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
     * Cancelamento de um serviço AGENDADO pelo cliente, com a penalização do
     * escalão (CancellationPolicy::scheduledPenaltyRatio).
     *
     * Sem penalização é o cancelamento de sempre. Com penalização: captura-se o
     * total (é o que o Payshop tem cativo), devolve-se ao cliente a parte que
     * não é penalização, e reparte-se o que fica 50/50 com o técnico — a mesma
     * repartição do cancelamento com o técnico a caminho, porque também aqui
     * não houve trabalho feito.
     *
     * A ordem importa: sem captura não se cobra ninguém e cai-se no
     * cancelamento normal, para nunca se depositar dinheiro que não se
     * conseguiu cobrar. Se o reembolso da diferença falhar, o cancelamento não
     * avança — mais vale o cliente continuar com o serviço marcado do que ficar
     * cobrado a 100% de uma penalização de 50%.
     *
     * As chamadas ao Payshop (capturar, reembolsar) correm FORA da transação de
     * BD: movem dinheiro no gateway e um rollBack não as reverteria. Só as
     * escritas de ledger (depósitos + estado) ficam na transação — locais e
     * atómicas. Assim nunca se desfaz em BD dinheiro que já se moveu no gateway;
     * uma falha depois da captura fica registada para reconciliação manual.
     *
     * @throws ExceptionInterface
     * @throws \Exception
     * @throws \Throwable
     */
    public function customerCancelScheduled(float $penaltyRatio): void
    {
        $this->service->refresh();

        if (! in_array($this->service->status, [ServiceStatus::PENDING, ServiceStatus::SCHEDULED], true)) {
            return;
        }

        $amount = abs((int) $this->service->getRawOriginal('amount'));
        $charge = CancellationPolicy::scheduledPenaltyAmount($amount, $penaltyRatio);

        if ($charge <= 0) {
            $this->customerCancel();

            return;
        }

        // Captura fora de qualquer transação de BD. Sem captura não se cobra
        // ninguém e cai-se no cancelamento normal (o customerCancel trata do
        // estado), para nunca se depositar dinheiro que não se conseguiu cobrar.
        if (! $this->capturePayment()) {
            $this->customerCancel();

            return;
        }

        // A partir daqui o total foi capturado no Payshop. O reembolso da
        // diferença (externo) e as escritas de ledger contam já com dinheiro
        // movido — qualquer falha tem de ficar registada para reconciliação.
        try {
            $refund = $amount - $charge;
            if ($refund > 0) {
                // Externo e fora da transação: se falhar, sobe a exceção antes de
                // qualquer escrita de ledger — nada em BD mudou e o serviço fica
                // marcado (o lado seguro). O valor capturado fica para reconciliar.
                $this->service->paymentOrder->refund($refund);
            }

            $split = CancellationPolicy::split($charge);

            // Só o ledger na transação: depósitos + estado, tudo local e atómico.
            \DB::transaction(function () use ($split) {
                $this->service->vendor->user->deposit($split['vendor'], $this->service->getMetaProduct());
                system_wallet()->deposit($split['platform'], $this->service->getMetaProduct());

                $this->service->skipCancellationRefund = true;
                $this->service->status = ServiceStatus::CANCELED;
                $this->service->status_justification = 'internal/services.cancel.charged';
                $this->service->save();
            });
        } catch (\Throwable $e) {
            // O total já foi capturado; se falhou o reembolso ou o ledger, o
            // dinheiro está movido mas a BD pode não refletir tudo — reportar
            // para reconciliar à mão (reembolso e/ou crédito ao técnico).
            report($e);
            throw $e;
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

        if (! in_array($this->service->status, [ServiceStatus::ACCEPTED, ServiceStatus::ARRIVED], true)) {
            return;
        }

        // Regra: depois de o técnico estar a caminho (on_the_way_at) ou em execução
        // (ARRIVED), cancelar COBRA 100% — captura-se o pagamento e reparte-se 50/50
        // (CancellationPolicy). Aceite mas ainda parado continua a reembolsar (o
        // ServiceObserver liberta o cativo ao gravar CANCELED), como sempre.
        if (CancellationPolicy::isChargeable($this->service)) {
            $this->cancelWithCharge();

            return;
        }

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

    /**
     * Cancelamento COBRADO (técnico a caminho / em execução).
     *
     * Captura os 100% e reparte 50% técnico / 50% plataforma. A ordem importa:
     *  1) capturar — se a captura FALHAR não se cobra ninguém, cai-se no
     *     cancelamento normal (reembolso/libertação pelo observer). Nunca se
     *     deposita a técnico/plataforma dinheiro que não se conseguiu cobrar.
     *  2) repartir e depositar.
     *  3) marcar CANCELED com a flag que impede o observer de reembolsar o que
     *     acabámos de capturar.
     *
     * NÃO VERIFICADO contra o Payshop (sem sandbox neste ambiente) — ver a nota
     * no fim do trabalho. A decisão e a repartição, essas, estão testadas.
     */
    private function cancelWithCharge(): void
    {
        \DB::beginTransaction();
        try {
            if (! $this->capturePayment()) {
                // Sem captura não há cobrança: encerra como cancelamento normal e
                // deixa o observer libertar o cativo.
                $this->service->status = ServiceStatus::CANCELED;
                $this->service->status_justification = 'internal/services.cancel.description';
                $this->service->save();

                \DB::commit();

                return;
            }

            $amount = abs((int) $this->service->getRawOriginal('amount'));
            $split = CancellationPolicy::split($amount);

            $this->service->vendor->user->deposit($split['vendor'], $this->service->getMetaProduct());
            system_wallet()->deposit($split['platform'], $this->service->getMetaProduct());

            $this->service->skipCancellationRefund = true;
            $this->service->status = ServiceStatus::CANCELED;
            $this->service->status_justification = 'internal/services.cancel.charged';
            $this->service->save();

            \DB::commit();
        } catch (\Exception $e) {
            \DB::rollBack();
            report($e);
            throw $e;
        }
    }

    /**
     * Captura o pagamento cativo. Espelha CloseService::capturePayment() — mesma
     * regra de idempotência (não voltar a confirmar uma order já SUCCESS).
     */
    private function capturePayment(): bool
    {
        $paymentOrder = $this->service->paymentOrder;

        if (! $paymentOrder) {
            return false;
        }

        if ($paymentOrder->status === Status::SUCCESS) {
            return true;
        }

        try {
            $paymentOrder->confirm();
        } catch (\Exception $e) {
            report($e);

            return false;
        }

        return $paymentOrder->status === Status::SUCCESS;
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
