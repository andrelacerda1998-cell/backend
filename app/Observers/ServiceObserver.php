<?php

namespace App\Observers;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Customer\ProfileCompletionNeeded;
use App\Jobs\Services\CreateInvoiceJob;
use App\Models\Service;
use App\Models\VoucherUsage;
use RwInteractive\PayshopSdk\Enums\Payment\Status as PaymentOrderStatus;

class ServiceObserver
{
    public function updating(Service $service): void
    {
        if (! $service->isDirty('status')) {
            return;
        }

        // Cancelamento JÁ COBRADO (a caminho / em execução): o dinheiro foi
        // capturado e repartido 50/50 no CancelService. Não reembolsar — era
        // devolver ao cliente o que já se pagou ao técnico e à plataforma.
        if ($service->skipCancellationRefund) {
            return;
        }

        // CANCELED and the terminal MBWay-failure statuses all release/refund the hold.
        // RefusedMbway/ExpiredMbway/CanceledMbway carry the reason on the status; the money handling is identical.
        if (! in_array($service->status, [
            ServiceStatus::CANCELED,
            ServiceStatus::REFUSED_MBWAY,
            ServiceStatus::EXPIRED_MBWAY,
            ServiceStatus::CANCELED_MBWAY,
        ], true)) {
            return;
        }

        $service->loadMissing('paymentOrder', 'customer');

        $paymentOrder = $service->paymentOrder;

        if ($paymentOrder) {
            try {
                // Re-sincroniza com o Payshop antes de decidir: o estado local pode estar
                // desatualizado (ex.: hold de cartão nunca refletido, push MBWay já recusado).
                // Best-effort — se o Payshop estiver indisponível, seguimos com o estado local
                // e o cancelamento nunca é bloqueado.
                try {
                    $paymentOrder->updateData();
                } catch (\Throwable $e) {
                    report($e);
                }

                // Estados terminais no Payshop: nada a libertar/reembolsar.
                $terminalStatuses = [
                    PaymentOrderStatus::CANCELLED,
                    PaymentOrderStatus::REFUNDED,
                    PaymentOrderStatus::EXPIRED,
                    PaymentOrderStatus::REFUSED,
                    PaymentOrderStatus::FRAUD,
                    PaymentOrderStatus::BLACKLISTED,
                    PaymentOrderStatus::USER_CANCELLED,
                    PaymentOrderStatus::THREEDS_EXPIRED,
                ];

                // Dinheiro já capturado: reembolsar.
                $capturedStatuses = [
                    PaymentOrderStatus::SUCCESS,
                    PaymentOrderStatus::PARTIALLY_CONFIRMED,
                    PaymentOrderStatus::PARTIALLY_REFUNDED,
                ];

                // Único estado com hold/autorização confirmada a libertar no Payshop (cativo
                // DEFERRED). Estados pré-confirmação (CREATED, PENDING_PAYMENT, PENDING_CARD,
                // PENDING_3DS_RESPONSE, REDIRECTED_TO_3DS, AUTHENTICATION_REQUIRED,
                // PENDING_PROCESSOR_RESPONSE) NÃO têm transação de confirmação: chamar cancel()
                // nesses rebenta 400 "no confirmation transaction" — deixá-los expirar sozinhos.
                $statesWithActiveHold = [
                    PaymentOrderStatus::PENDING_CONFIRMATION,
                ];

                if (in_array($paymentOrder->status, $terminalStatuses, true)) {
                    // nada a fazer — a ordem já está resolvida no Payshop
                } elseif (in_array($paymentOrder->status, $capturedStatuses, true)) {
                    $paymentOrder->refund();
                } elseif (in_array($paymentOrder->status, $statesWithActiveHold, true)) {
                    try {
                        $paymentOrder->cancel();
                    } catch (\Throwable $e) {
                        // Uma libertação falhada nunca pode bloquear a transição de estado do
                        // serviço — o cliente nunca fica preso; o hold expira por si.
                        report($e);
                    }
                }
                // Restantes estados (pré-confirmação): nenhuma ação — sem hold a libertar.
            } catch (\Throwable $e) {
                // Não engolir em silêncio: uma libertação falhada tem de ficar visível no
                // error tracking. O cancelamento prossegue na mesma (o cliente nunca fica preso).
                report($e);
            }
        }

        // Idempotência (defesa em profundidade contra a corrida job-vs-poll): não reembolsar o
        // credit_used se este serviço já estava REFUNDED na BD quando foi carregado — evita um
        // 2º crédito numa segunda save sobre uma instância stale.
        if ($service->credit_used > 0
            && $service->getRawOriginal('payment_status') !== PaymentStatus::REFUNDED->value) {
            $customer = $service->customer;
            $customer->deposit($service->credit_used, [
                'description' => 'internal/services.refunds.refused',
                'type' => 'internal/services.transactions_type.refund',
                'class' => 'App\\Models\\User',
                'id' => $customer->id,
                'admin_description' => 'internal/services.refunds.refused',
            ]);
        }

        if (
            $service->payment_status === PaymentStatus::PAID ||
            ($service->payment_status !== PaymentStatus::REFUNDED && $service->credit_used > 0)
        ) {
            $service->payment_status = PaymentStatus::REFUNDED;
        }

        // Release any single-use voucher applied to this service, so a canceled/failed/expired
        // request does not permanently consume the customer's voucher.
        VoucherUsage::where('service_id', $service->id)->delete();
    }

    public function updated(Service $service): void
    {
        if ($service->isDirty('status')) {
            if ($service->status === ServiceStatus::CLOSED && ! $service->is_test) {
                CreateInvoiceJob::dispatch($service)->delay(now()->addSeconds(30));

                $service->loadMissing('customer');
                $customer = $service->customer;

                if ($customer && $customer->isPhoneOnly()) {
                    $customer->update(['profile_completion_pending' => true]);
                    ProfileCompletionNeeded::dispatch($customer);
                }
            }
        }
    }
}
