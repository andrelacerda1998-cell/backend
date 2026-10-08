<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Str;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Models\PaymentOrder;
use Tests\TestCase;

/**
 * O detalhe de um pedido no backoffice traz o que é preciso para o resolver
 * sem sair dele: o telefone do técnico e o pagamento ligado ao pedido (para
 * o reembolso). A listagem não traz nenhum dos dois.
 */
class AdminServiceDetalheContactosTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = ['services', 'users', 'vendors', 'payment_orders', 'wallets'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        config(['services.admin_api.token' => 'a-valid-token']);
    }

    private function pedido(): Service
    {
        $cliente = User::factory()->create(['phone_number' => '+351912000111']);
        $tecnico = Vendor::factory()->create();
        $tecnico->user->forceFill(['phone_number' => '+351936000222'])->save();

        $ordem = PaymentOrder::create([
            'user_id' => $cliente->id,
            'uuid' => $uuid = (string) Str::uuid(),
            'amount' => 4500,
            'paid' => true,
            'status' => Status::SUCCESS,
            'type' => OperationType::DEFERRED,
            'refunded' => 0,
            'service' => 'fake',
            'service_uuid' => (string) Str::uuid(),
            'token' => 'fake-token',
        ]);

        $servico = Service::factory()->create(['customer_id' => $cliente->id, 'vendor_id' => $tecnico->id]);
        $servico->forceFill(['payment_order_id' => $ordem->id])->save();

        return $servico->refresh();
    }

    public function test_o_detalhe_traz_o_telefone_do_tecnico_e_o_pagamento(): void
    {
        $servico = $this->pedido();

        $r = $this->withHeaders(['Authorization' => 'Bearer a-valid-token'])
            ->getJson("/api/v1/admin/services/{$servico->id}")->assertOk();

        $this->assertSame('+351912000111', $r->json('data.customer_phone'));
        $this->assertSame('+351936000222', $r->json('data.technician_phone'));
        $this->assertSame($servico->paymentOrder->uuid, $r->json('data.payment_order_uuid'));
    }

    public function test_a_listagem_nao_espalha_o_telefone_do_tecnico(): void
    {
        $this->pedido();

        $r = $this->withHeaders(['Authorization' => 'Bearer a-valid-token'])
            ->getJson('/api/v1/admin/services')->assertOk();

        $this->assertArrayNotHasKey('technician_phone', $r->json('data.items.0'));
        $this->assertArrayNotHasKey('payment_order_uuid', $r->json('data.items.0'));
    }
}
