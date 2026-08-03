<?php

namespace Tests\Support;

use App\Services\Common\Services\ChargeServiceExtra;
use Illuminate\Support\Str;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Exceptions\Api\CreditCardValidationRequired;
use RwInteractive\PayshopSdk\Models\PaymentMethod;
use RwInteractive\PayshopSdk\Models\PaymentOrder;

/**
 * Duplo de teste do ChargeServiceExtra: substitui a fronteira Payshop (os 5
 * métodos protected já isolados no próprio ficheiro para isto) por um
 * desfecho configurável, sem nenhuma chamada HTTP real. `$outcome` decide o
 * resultado da PRÓXIMA cobrança tentada.
 */
class FakeChargeServiceExtra extends ChargeServiceExtra
{
    /** 'success' | '3ds' | 'declined' */
    public string $outcome = 'success';

    /** URL devolvido quando $outcome === '3ds' (para os testes confirmarem que é guardado). */
    public string $validationUrl = 'https://fake-payshop.test/3ds/validate';

    protected function createCardOrder($customer, $service, $extra): PaymentOrder
    {
        return $this->fakeOrder($customer);
    }

    protected function createMbwayOrder($customer, $service, $extra, $mbway): PaymentOrder
    {
        return $this->fakeOrder($customer);
    }

    protected function authorizeOrder(PaymentOrder $order, PaymentMethod $card): void
    {
        if ($this->outcome === '3ds') {
            throw new CreditCardValidationRequired($this->validationUrl);
        }
    }

    protected function captureOrder(PaymentOrder $order): void
    {
        $order->status = $this->outcome === 'declined' ? Status::REFUSED : Status::SUCCESS;
        $order->save();
    }

    protected function pushOrder(PaymentOrder $order): void
    {
        // MBWay: charge() marca pending_confirmation independentemente do estado da ordem aqui.
    }

    private function fakeOrder($customer): PaymentOrder
    {
        return PaymentOrder::create([
            'user_id' => $customer->id,
            'uuid' => (string) Str::uuid(),
            'amount' => 100,
            'paid' => false,
            'status' => Status::CREATED,
            'type' => OperationType::DEFERRED,
            'refunded' => 0,
            'service' => 'fake',
            'service_uuid' => (string) Str::uuid(),
            'token' => 'fake-token',
        ]);
    }
}
