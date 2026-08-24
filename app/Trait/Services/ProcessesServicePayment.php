<?php

namespace App\Trait\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use RwInteractive\PayshopSdk\Exceptions\Api\CreditCardValidationRequired;
use App\Models\PaymentMethod;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;

/**
 * Cobrança de um serviço: cartão (com 3DS) e MBWay.
 *
 * Movido do OpenServiceController sem alterações, para o checkout da seleção
 * de profissional usar EXATAMENTE este caminho. Um segundo caminho de cobrança
 * seria a forma mais rápida de as duas implementações divergirem em silêncio —
 * e divergirem aqui significa cobrar mal a alguém.
 */
trait ProcessesServicePayment
{
    protected function processCreditCardPayment($customer, $service, $vendor, $total, $paymentMethod): ?string
    {
        $validationUrl = null;

        if ($total['balance'] > 0) {
            $customer->withdraw($total['balance_total_used']);
        }

        if ($total['value_for_payment'] > 0) {
            $paymentOrder = null;
            try {
                $paymentOrder = $customer->createPaymentOrder(
                    OperationType::DEFERRED,
                    $total['value_for_payment'],
                    'Payment for service',
                    now()->addDays(15),
                    [],
                    ['service' => $service->id]
                );

                $service->payment_order_id = $paymentOrder->id;
                $paymentOrder->authorize($paymentMethod);

                $service->payment_status = PaymentStatus::PAID;
            } catch (CreditCardValidationRequired $e) {
                $service->payment_status = PaymentStatus::PENDING;
                $service->status = ServiceStatus::PENDING_3DS;
                $validationUrl = $e->getUrl();
            } catch (\Throwable $e) {
                // A order remota já pode ter sido criada, mas o creditCard() vai fazer rollBack()
                // e desfazer o Service/wallet localmente. Cancelar best-effort para não deixar uma
                // autorização órfã num cartão real. Try próprio: nunca mascara o erro original nem
                // quebra o fluxo; a seguir re-lança para o rollBack seguir exatamente igual.
                if ($paymentOrder) {
                    try {
                        $paymentOrder->cancel();
                    } catch (\Throwable $cancelError) {
                        report($cancelError);
                    }
                }

                throw $e;
            }
        } else {
            $service->payment_status = PaymentStatus::PAID;
        }

        $service->save();

        return $validationUrl;
    }

    protected function processMbwayPayment(User $customer, Service $service, Vendor $vendor, $total, PaymentMethod $paymentMethod): ?string
    {
        $validationUrl = null;

        if ($total['balance'] > 0) {
            $customer->withdraw($total['balance_total_used']);
        }

        if ($total['value_for_payment'] > 0) {

            $paymentOrder = $customer->createMbWayPaymentOrder(
                OperationType::DEFERRED,
                $total['value_for_payment'],
                'Payment for service via MBWay',
                now()->addDays(15),
                $paymentMethod,
                ['service' => $service->id]
            );

            $paymentOrder->process();

            $service->payment_order_id = $paymentOrder->id;

            $service->payment_status = PaymentStatus::PENDING;
        } else {
            $service->payment_status = PaymentStatus::PAID;
        }

        $service->save();

        return 'check bank app';
    }
}
