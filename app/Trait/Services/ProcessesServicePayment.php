<?php

namespace App\Trait\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Carteira\CarteiraDoCliente;
use RwInteractive\PayshopSdk\Api\Payments\WalletPayment;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\Payment\Wallet;
use RwInteractive\PayshopSdk\Exceptions\Api\CreditCardValidationRequired;

/**
 * Cobrança de um serviço: cartão (com 3DS), MBWay e carteiras.
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

        $this->debitarCarteira($customer, $service, $total);

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

    /**
     * Cobrança com Apple Pay ou Google Pay.
     *
     * É o caminho do cartão com uma única diferença: em vez de autorizar a
     * ordem contra um método de pagamento guardado, paga-a com o payload que a
     * carteira acabou de devolver ao telemóvel. Tudo o resto — a ordem
     * DEFERRED, o saldo usado, o 3DS, o cancelamento da ordem órfã quando algo
     * rebenta — é literalmente o mesmo, e é para continuar a ser: duas
     * implementações de cobrança divergem em silêncio, e divergir aqui
     * significa cobrar mal a alguém.
     *
     * Não há método de pagamento para resolver nem para guardar. Um payload de
     * carteira é de uso único: serve esta ordem e morre. Por isso o cliente não
     * fica com um "cartão" novo na lista depois de pagar com Apple Pay.
     *
     * POR CONFIRMAR: que o Payshop honre o DEFERRED numa carteira — cativar
     * agora e capturar quando o serviço fecha. A ordem é criada exactamente
     * como a do cartão e o exemplo da documentação devolve
     * `operative: AUTHORIZATION`, mas isso é um exemplo e não um teste. Até
     * alguém pagar com um telemóvel a sério e o CloseService capturar, isto
     * não está provado.
     */
    protected function processWalletPayment(
        $customer,
        $service,
        $vendor,
        $total,
        Wallet $wallet,
        array|string $payload,
        ?string $customerIp = null,
    ): ?string {
        $validationUrl = null;

        $this->debitarCarteira($customer, $service, $total);

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

                /*
                 * O Payshop quer o payload como STRING, não como objeto aninhado.
                 *
                 * O exemplo que eles mandaram (30/09) traz `"payload": "{...}"` --
                 * um JSON dentro de uma string. A app manda o `PaymentData` como
                 * objeto, que é o natural em JSON, e sem esta linha ele seguia
                 * aninhado no corpo do pedido. Daí o "payload está incorreto" e o
                 * `validation_hash` vazio.
                 *
                 * Só se codifica o que vem como array: o Apple Pay manda uma
                 * string e essa passa intacta.
                 */
                $payloadParaPayshop = is_array($payload)
                    ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : $payload;

                app(WalletPayment::class)->pay($paymentOrder->uuid, $wallet, $payloadParaPayshop, $customerIp);

                $service->payment_status = PaymentStatus::PAID;
            } catch (CreditCardValidationRequired $e) {
                // Só o Google Pay chega aqui: o Apple Pay conta como pagamento
                // seguro. O estado é o mesmo do cartão, de propósito — a app já
                // sabe abrir este URL e voltar a perguntar pelo pagamento.
                $service->payment_status = PaymentStatus::PENDING;
                $service->status = ServiceStatus::PENDING_3DS;
                $validationUrl = $e->getUrl();
            } catch (\Throwable $e) {
                // A ordem remota pode já existir e o rollBack só desfaz o lado
                // de cá. Cancelar best-effort para não deixar uma autorização
                // pendurada no cartão de alguém.
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

    protected function processMbwayPayment(User $customer, Service $service, Vendor $vendor, $total, $paymentMethod): ?string
    {
        $validationUrl = null;

        $this->debitarCarteira($customer, $service, $total);

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

    /**
     * Tira da Carteira o que o cálculo decidiu usar: o Saldo pelo caminho de
     * sempre (`withdraw` na carteira `default`) e o crédito de convites pela
     * CarteiraDoCliente, que regista de que crédito saiu cada cêntimo.
     *
     * Um `$total` sem as partes (cálculos antigos) é todo Saldo — exatamente o
     * que acontecia antes.
     */
    private function debitarCarteira($customer, $service, array $total): void
    {
        $convites = (int) ($total['balance_convites_used'] ?? 0);
        $saldo = (int) ($total['balance_saldo_used'] ?? (($total['balance_total_used'] ?? 0) - $convites));

        if ($saldo > 0) {
            // O meta é o que deixa a app mostrar "Usado: <serviço>" nos movimentos.
            $customer->withdraw($saldo, ['type' => 'service_payment', 'service_id' => $service->id]);
        }

        if ($convites > 0) {
            app(CarteiraDoCliente::class)->debitarConvites($customer, $service, $convites);
        }
    }
}
