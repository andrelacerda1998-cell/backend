<?php

namespace App\Services\Common\Services;

use App\Enums\Services\ServiceStatus;
use App\Jobs\Services\CreateCancellationInvoiceJob;
use App\Jobs\Services\CreateVendorCancellationInvoiceJob;
use App\Models\Service;
use App\Models\ServiceExtra;
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

        $order = $this->service->paymentOrder;

        // Sincroniza o estado do Payshop antes de decidir (best-effort). Importa
        // numa RETRY: se a tentativa anterior já reembolsou mas o ledger falhou,
        // o serviço ficou marcado e voltamos aqui — o refund não pode repetir-se.
        try {
            $order?->updateData();
        } catch (\Throwable $e) {
            report($e);
        }

        // Idempotência do refund (único efeito externo não-reversível por retry):
        // se a order já está (parcialmente) reembolsada, o reembolso desta
        // penalização já aconteceu antes. Não recapturar nem reembolsar — só
        // concluir o ledger, que num rollBack anterior não chegou a persistir.
        $alreadyRefunded = $order && in_array($order->status, [Status::PARTIALLY_REFUNDED, Status::REFUNDED], true);

        // Captura fora de qualquer transação de BD. Sem captura não se cobra
        // ninguém e cai-se no cancelamento normal (o customerCancel trata do
        // estado), para nunca se depositar dinheiro que não se conseguiu cobrar.
        if (! $alreadyRefunded && ! $this->capturePayment()) {
            $this->customerCancel();

            return;
        }

        // A partir daqui o total foi capturado no Payshop. O reembolso da
        // diferença (externo) e as escritas de ledger contam já com dinheiro
        // movido — qualquer falha tem de ficar registada para reconciliação.
        try {
            $refund = $amount - $charge;
            if ($refund > 0 && ! $alreadyRefunded) {
                // Externo e fora da transação: se falhar, sobe a exceção antes de
                // qualquer escrita de ledger — nada em BD mudou e o serviço fica
                // marcado (o lado seguro). O valor capturado fica para reconciliar.
                $order->refund($refund);
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
     * Cancelamento por FALTA DO TÉCNICO — o cliente é reembolsado, sempre.
     *
     * Existe à parte do `cancelOpenService()` por uma razão que custa dinheiro
     * a quem se engane: esse, quando o técnico já marcou "a caminho", chama o
     * `cancelWithCharge()` — cobra 100% ao cliente e reparte 50/50 com o
     * técnico. É a regra certa para o CLIENTE que desiste em cima da hora, e
     * exatamente ao contrário do que se quer aqui: numa falta, o cliente
     * ficaria cobrado por um serviço que ninguém fez e o técnico receberia
     * metade ao mesmo tempo que é penalizado.
     *
     * O `customerCancel()` também não serve: só age em PENDING/SCHEDULED, e um
     * técnico que marcou "a caminho" e nunca apareceu deixa o serviço em
     * ACCEPTED. Ficava preso nesse estado, sem nunca chegar a CANCELED — e o
     * reembolso vive no ServiceObserver, que só dispara nessa transição.
     * (Encontrado pelo Rodrigo na revisão dos PR #21–#30.)
     */
    public function vendorNoShowCancel(): void
    {
        $this->service->refresh();

        if (! in_array($this->service->status, [
            ServiceStatus::PENDING,
            ServiceStatus::SCHEDULED,
            ServiceStatus::ACCEPTED,
        ], true)) {
            return;
        }

        \DB::transaction(function () {
            // `skipCancellationRefund` fica a false de propósito: é o que deixa
            // o ServiceObserver libertar/reembolsar o pagamento ao gravar.
            $this->service->status = ServiceStatus::CANCELED;
            $this->service->status_justification = 'internal/services.cancel.vendor_no_show';
            $this->service->save();
        });
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
    /**
     * Cancelar um pedido personalizado que ainda está em análise.
     *
     * Não há técnico (nenhum foi convidado), não há pagamento (a ordem só
     * nasce no checkout) e não há política de cancelamento a aplicar. Por isso
     * não é o `cancelOpenService`, que exige ACCEPTED/ARRIVED e fazia `return`
     * silencioso aqui — o cliente carregava em cancelar e a API respondia que
     * não era possível, sem lhe dar saída nenhuma.
     */
    public function customerCancelUnderReview(): void
    {
        $this->service->refresh();

        if ($this->service->status !== ServiceStatus::PENDING_REVIEW) {
            return;
        }

        \DB::transaction(function () {
            $this->service->status = ServiceStatus::CANCELED;
            $this->service->status_justification = 'internal/services.cancel.description';
            $this->service->save();
        });
    }

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

            // Fora da transação do cancelamento: o reembolso de um extra é uma
            // chamada ao Payshop. Ver `resolverExtrasAoCancelar`.
            $this->resolverExtrasAoCancelar();

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

        $this->resolverExtrasAoCancelar();
    }

    /**
     * OS EXTRAS DE UM SERVIÇO QUE MORRE (decisão do André, 02/10/2026).
     *
     * Um extra é cobrado ao cliente NO MOMENTO DA APROVAÇÃO -- captura
     * imediata, não no fecho. E um extra só existe com o serviço em ARRIVED,
     * que é um dos estados de onde ainda se pode cancelar. O cancelamento não
     * lhes tocava, e quem credita extras (`CloseService`) nunca corre num
     * serviço cancelado: o cliente ficava pago, o técnico sem nada, e o
     * dinheiro parado -- `approved`/`paid` para sempre, sem ninguém saber que
     * existia.
     *
     * A regra segue a mesma lógica do serviço base, que já cobra a deslocação
     * (aconteceu) e não cobra o trabalho (não aconteceu):
     *
     *  - PEÇA: credita-se 100% ao técnico. Ele comprou-a do bolso e, estando no
     *    local, muito provavelmente já a instalou. Reembolsar o cliente seria
     *    este ficar com a torneira E com o dinheiro.
     *
     *  - TEMPO EXTRA: reembolsa-se o cliente. É trabalho que não foi feito.
     *
     * O que NÃO é opcional, decida-se o que se decidir: o extra tem de ficar
     * RESOLVIDO. Ficar pendurado é o pior dos estados.
     *
     * Corre FORA de qualquer transação: o reembolso é uma chamada ao Payshop, e
     * uma chamada de rede dentro de uma transação aberta segura a linha da base
     * de dados durante todo o tempo de resposta do gateway. Idempotente pelos
     * mesmos guardas do fecho (`vendor_credited_at`) e pelo estado do pagamento.
     */
    public function resolverExtrasAoCancelar(): void
    {
        $extras = $this->service->extras()->where('status', 'approved')->get();

        foreach ($extras as $extra) {
            // Nunca entrou dinheiro (falhou a cobrança, ficou a meio do 3DS):
            // não há o que devolver nem o que creditar.
            if (! $extra->isCharged()) {
                continue;
            }

            try {
                if ($extra->type === 'part') {
                    $this->creditarPecaAoTecnico($extra);

                    continue;
                }

                $this->reembolsarTempoExtra($extra);
            } catch (\Throwable $e) {
                // Uma falha num extra não pode desfazer o cancelamento, que já
                // está feito e já foi comunicado. Fica o registo para
                // reconciliação.
                \Log::error('[cancelar] falhou a resolver o extra #'.$extra->id, [
                    'servico' => $this->service->id,
                    'erro' => $e->getMessage(),
                ]);
            }
        }
    }

    /** Peça: inteira para quem a pagou. */
    private function creditarPecaAoTecnico(ServiceExtra $extra): void
    {
        // Já creditado (retry, ou o fecho correu antes): não pagar duas vezes.
        if ($extra->vendor_credited_at !== null || (int) $extra->amount <= 0) {
            return;
        }

        $meta = $this->service->getMetaProduct();
        $meta['description'] = ($meta['description'] ?? '').' — peça #'.$extra->id.' (serviço cancelado)';
        $meta['extra_id'] = $extra->id;

        $this->service->vendor->user->deposit((int) $extra->amount, $meta);
        $extra->forceFill(['vendor_credited_at' => now()])->save();
    }

    /** Tempo extra: devolve-se, porque não foi trabalhado. */
    private function reembolsarTempoExtra(ServiceExtra $extra): void
    {
        if ($extra->payment_status === 'refunded') {
            return;
        }

        // `not_required` nunca passou pelo gateway (serviço de teste, extra a
        // 0 €): não há ordem para reembolsar, só estado para fechar.
        if ($extra->payment_status === 'paid' && $extra->paymentOrder) {
            $extra->paymentOrder->refund((int) $extra->amount);
        }

        $extra->forceFill(['payment_status' => 'refunded'])->save();
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
