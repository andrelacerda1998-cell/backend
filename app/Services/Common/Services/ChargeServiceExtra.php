<?php

namespace App\Services\Common\Services;

use App\Enums\Services\PaymentStatus;
use App\Models\Service;
use App\Models\ServiceExtra;
use Illuminate\Support\Facades\Log;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\PaymentMethods\PaymentMethodType;
use RwInteractive\PayshopSdk\Exceptions\Api\CreditCardValidationRequired;
use RwInteractive\PayshopSdk\Models\PaymentMethod;
use RwInteractive\PayshopSdk\Models\PaymentOrder;

/**
 * Cobrança de um extra (tempo/peças) APROVADO pelo cliente — ordem Payshop dedicada,
 * separada da ordem do serviço base (que nunca é alterada).
 *
 * Desenho:
 *  - Cartão gravado: MIT via `payment/direct` (authorize com card_uuid) + captura imediata
 *    (confirmation). Se o Payshop exigir 3DS (303) → `requires_action`: fica aprovado mas
 *    a cobrança precisa de fluxo na app do cliente.
 *  - MBWay: push para o telemóvel do cliente → `pending_confirmation`; a captura é feita
 *    no fecho do serviço (CloseService) depois de o cliente aceitar o push.
 *  - Serviços de teste ou extras de 0€ → `not_required`.
 *
 * Idempotência: o chamador tranca a linha do extra; aqui só se cobra quando ainda não há
 * payment_order_id (nenhuma ordem viva/terminal no Payshop) nem estado terminal (paid/
 * not_required), e a coluna payment_order_id é UNIQUE. Isto permite retry seguro só no
 * único caso sem ordem criada ('requires_action' por falta de método de pagamento).
 */
class ChargeServiceExtra
{
    /**
     * Tenta cobrar o extra. Devolve o payment_status resultante:
     * paid | not_required | pending_confirmation | requires_action | failed
     *
     * Pré-condição: $extra está status=approved e trancado (lockForUpdate) pelo chamador.
     */
    public function charge(Service $service, ServiceExtra $extra): string
    {
        // Já cobrado / dispensado — nunca recobrar.
        if ($extra->isCharged()) {
            return $extra->payment_status;
        }

        // Já existe uma ordem viva no Payshop (3DS a aguardar o cliente, ou push MBWay a
        // aguardar confirmação) — nunca criar uma segunda ordem para o mesmo extra; o
        // cliente resolve pela ordem existente (ecrã de validação) ou o fecho do serviço
        // tenta capturar o MBWay. Sem ordem (1ª tentativa, ou retry depois de
        // 'no_stored_payment_method' — aí nunca chegou a criar-se ordem nenhuma), segue.
        if ($extra->payment_order_id !== null) {
            return $extra->payment_status;
        }

        if ($service->is_test || (int) $extra->amount <= 0) {
            $extra->forceFill(['payment_status' => 'not_required', 'charged_at' => now()])->save();

            return 'not_required';
        }

        $customer = $service->customer;
        $basePaymentMethod = $service->paymentOrder?->payment_method_id
            ? PaymentMethod::find($service->paymentOrder->payment_method_id)
            : null;

        try {
            if ($basePaymentMethod && $basePaymentMethod->type === PaymentMethodType::MBWAY) {
                return $this->chargeMbway($service, $extra, $customer, $basePaymentMethod);
            }

            // Cartão da ordem base, ou (serviço pago só com crédito/carteira) o último cartão gravado.
            $card = $basePaymentMethod ?? $customer->paymentMethods()->where('type', '!=', 'mbway')->latest()->first();

            if (! $card) {
                // Sem método reutilizável — precisa de fluxo na app do cliente.
                $extra->forceFill(['payment_status' => 'requires_action', 'payment_error' => 'no_stored_payment_method'])->save();

                return 'requires_action';
            }

            return $this->chargeCard($service, $extra, $customer, $card);
        } catch (CreditCardValidationRequired $e) {
            // O Payshop exige nova autenticação (3DS) para esta cobrança MIT — não simulamos:
            // fica aprovado à espera de fluxo na app do cliente. Guarda o URL de validação
            // (perdia-se antes) para a app poder reabrir o ecrã de confirmação mais tarde.
            $extra->forceFill([
                'payment_status' => 'requires_action',
                'payment_error' => '3ds_required',
                'payment_validation_url' => $e->getUrl(),
            ])->save();

            return 'requires_action';
        } catch (\Throwable $e) {
            Log::warning('Service extra charge failed', ['extra_id' => $extra->id, 'error' => $e->getMessage()]);
            $extra->forceFill(['payment_status' => 'failed', 'payment_error' => mb_substr($e->getMessage(), 0, 255)])->save();

            return 'failed';
        }
    }

    private function chargeCard(Service $service, ServiceExtra $extra, $customer, PaymentMethod $card): string
    {
        $order = $this->createCardOrder($customer, $service, $extra);

        // Gravar já a ligação à ordem: mesmo que a captura falhe a meio, fica o rasto e
        // nunca se cria uma segunda ordem para o mesmo extra (payment_order_id é UNIQUE).
        $extra->forceFill(['payment_order_id' => $order->id])->save();

        $this->authorizeOrder($order, $card);   // MIT com o cartão gravado (pode lançar 3DS)
        $this->captureOrder($order);            // captura imediata — a cobrança acontece na aprovação

        if ($order->status === \RwInteractive\PayshopSdk\Enums\Payment\Status::SUCCESS) {
            $extra->forceFill(['payment_status' => 'paid', 'charged_at' => now(), 'payment_error' => null])->save();

            return 'paid';
        }

        $extra->forceFill(['payment_status' => 'failed', 'payment_error' => 'capture_status_'.($order->status->value ?? 'unknown')])->save();

        return 'failed';
    }

    private function chargeMbway(Service $service, ServiceExtra $extra, $customer, PaymentMethod $mbway): string
    {
        $order = $this->createMbwayOrder($customer, $service, $extra, $mbway);

        $extra->forceFill(['payment_order_id' => $order->id])->save();

        $this->pushOrder($order); // push MB WAY — o cliente confirma na app do banco

        $extra->forceFill(['payment_status' => 'pending_confirmation'])->save();

        return 'pending_confirmation';
    }

    // ---- Fronteira externa (Payshop). Isolada em métodos próprios para poder ser
    // ---- substituída em testes/tinker sem tocar na lógica de estados acima. ----

    protected function createCardOrder($customer, Service $service, ServiceExtra $extra): PaymentOrder
    {
        // 'extra' entra na querystring do URL de retorno assinado (o {service} da rota fica
        // igual ao do pedido base — a ordem pertence ao MESMO service_id). SEM ISTO, o
        // callback do 3DS cairia no controller do serviço base, que já está pago nesta
        // altura (o extra só existe com o serviço a decorrer) e devolveria 400 "already
        // paid" ao cliente em vez de confirmar a cobrança do extra.
        return $customer->createPaymentOrder(
            OperationType::DEFERRED,
            (int) $extra->amount,
            'Extra for service #'.$service->id,
            now()->addDays(15),
            [],
            ['service' => $service->id, 'extra' => $extra->id]
        );
    }

    protected function createMbwayOrder($customer, Service $service, ServiceExtra $extra, PaymentMethod $mbway): PaymentOrder
    {
        return $customer->createMbWayPaymentOrder(
            OperationType::DEFERRED,
            (int) $extra->amount,
            'Extra for service #'.$service->id.' via MBWay',
            now()->addDays(15),
            $mbway,
            ['service' => $service->id]
        );
    }

    protected function authorizeOrder(PaymentOrder $order, PaymentMethod $card): void
    {
        $order->authorize($card);
    }

    protected function captureOrder(PaymentOrder $order): void
    {
        $order->confirm();
    }

    protected function pushOrder(PaymentOrder $order): void
    {
        $order->process();
    }
}
