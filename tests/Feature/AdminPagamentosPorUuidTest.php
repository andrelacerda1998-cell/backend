<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\ServiceExtra;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Models\PaymentOrder;
use Tests\TestCase;

/**
 * De que pedido é um pagamento do Payshop: o backoffice vê os pagamentos pelo
 * uuid e precisa de chegar ao pedido (e ao cliente) sem ir à base de dados.
 */
class AdminPagamentosPorUuidTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        config(['services.admin_api.token' => 'a-valid-token']);
        Notification::fake();
        Queue::fake();
    }

    private function api(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    private function ordem(User $quemPagou, int $centimos = 4500, Status $estado = Status::SUCCESS): PaymentOrder
    {
        return PaymentOrder::create([
            'user_id' => $quemPagou->id,
            'uuid' => (string) Str::uuid(),
            'amount' => $centimos,
            'paid' => true,
            'status' => $estado,
            'type' => OperationType::DEFERRED,
            'refunded' => 0,
            'service' => 'fake',
            'service_uuid' => (string) Str::uuid(),
            'token' => 'fake-token',
        ]);
    }

    private function pedidoPagoCom(PaymentOrder $ordem, User $cliente): Service
    {
        $pedido = Service::factory()->create(['customer_id' => $cliente->id]);
        $pedido->forceFill(['payment_order_id' => $ordem->id])->save();

        return $pedido;
    }

    public function test_diz_de_que_pedido_e_cada_pagamento_pela_ordem_pedida(): void
    {
        $ana = User::factory()->create();
        $doPedido = $this->ordem($ana, 4500);
        $pedido = $this->pedidoPagoCom($doPedido, $ana);

        // O pagamento de um EXTRA (tempo ou peças) leva ao pedido do extra.
        $bruno = User::factory()->create();
        $doExtra = $this->ordem($bruno, 1200, Status::PENDING_CONFIRMATION);
        $pedidoDoExtra = Service::factory()->create(['customer_id' => $bruno->id]);
        $extra = ServiceExtra::create([
            'service_id' => $pedidoDoExtra->id,
            'type' => 'time',
            'minutes' => 30,
            'amount' => 1200,
            'status' => 'approved',
            'payment_order_id' => $doExtra->id,
        ]);

        // Um pagamento sem pedido: fica o dono da ordem.
        $carla = User::factory()->create();
        $solto = $this->ordem($carla, 999, Status::CANCELLED);

        $uuids = [$solto->uuid, 'nao-existe', $doPedido->uuid, $doExtra->uuid];

        $r = $this->api()->getJson('/api/v1/admin/payment-orders?uuids='.implode(',', $uuids))->assertOk();

        $this->assertSame([
            [
                'uuid' => $solto->uuid,
                'service_id' => null,
                'service_extra_id' => null,
                'customer_id' => $carla->id,
                'status' => 'CANCELLED',
                'amount' => 999,
                'refunded' => 0,
            ],
            [
                'uuid' => $doPedido->uuid,
                'service_id' => $pedido->id,
                'service_extra_id' => null,
                'customer_id' => $ana->id,
                'status' => 'SUCCESS',
                'amount' => 4500,
                'refunded' => 0,
            ],
            [
                'uuid' => $doExtra->uuid,
                'service_id' => $pedidoDoExtra->id,
                'service_extra_id' => $extra->id,
                'customer_id' => $bruno->id,
                'status' => 'PENDING_CONFIRMATION',
                'amount' => 1200,
                'refunded' => 0,
            ],
        ], $r->json('data.items'));
        $this->assertSame(['nao-existe'], $r->json('data.nao_encontrados'));
    }

    public function test_aceita_espacos_e_repetidos_na_lista(): void
    {
        $ana = User::factory()->create();
        $ordem = $this->ordem($ana);

        $this->api()->getJson('/api/v1/admin/payment-orders?uuids='.urlencode(" {$ordem->uuid} ,{$ordem->uuid},,"))
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.uuid', $ordem->uuid)
            ->assertJsonPath('data.nao_encontrados', []);
    }

    /** O backoffice lê os uuids do Payshop em MAIÚSCULAS; a base pode tê-los noutra forma. */
    public function test_encontra_o_pagamento_com_maiusculas_ou_minusculas(): void
    {
        $ana = User::factory()->create();
        $ordem = $this->ordem($ana);
        $pedido = $this->pedidoPagoCom($ordem, $ana);
        $maiusculas = strtoupper($ordem->uuid);
        $this->assertNotSame($maiusculas, $ordem->uuid);

        $this->api()->getJson('/api/v1/admin/payment-orders?uuids='.$maiusculas.','.$ordem->uuid)
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.service_id', $pedido->id)
            ->assertJsonPath('data.nao_encontrados', []);

        $this->api()->getJson("/api/v1/admin/payment-orders/{$maiusculas}")->assertOk()
            ->assertJsonPath('data.service_id', $pedido->id);
    }

    public function test_sem_uuids_ou_com_mais_de_duzentos_e_recusado(): void
    {
        $this->api()->getJson('/api/v1/admin/payment-orders')->assertStatus(422);
        $this->api()->getJson('/api/v1/admin/payment-orders?uuids=,,')->assertStatus(422);

        $demais = collect(range(1, 201))->map(fn () => (string) Str::uuid())->implode(',');
        $this->api()->getJson('/api/v1/admin/payment-orders?uuids='.$demais)->assertStatus(422);

        $duzentos = collect(range(1, 200))->map(fn () => (string) Str::uuid())->implode(',');
        $this->api()->getJson('/api/v1/admin/payment-orders?uuids='.$duzentos)->assertOk()
            ->assertJsonCount(200, 'data.nao_encontrados');
    }

    public function test_um_pagamento_pelo_uuid(): void
    {
        $ana = User::factory()->create();
        $ordem = $this->ordem($ana);
        $pedido = $this->pedidoPagoCom($ordem, $ana);

        $this->api()->getJson("/api/v1/admin/payment-orders/{$ordem->uuid}")->assertOk()
            ->assertJsonPath('data.uuid', $ordem->uuid)
            ->assertJsonPath('data.service_id', $pedido->id)
            ->assertJsonPath('data.customer_id', $ana->id)
            ->assertJsonPath('data.status', 'SUCCESS')
            ->assertJsonPath('data.amount', 4500);
    }

    public function test_um_uuid_desconhecido_da_404(): void
    {
        $this->api()->getJson('/api/v1/admin/payment-orders/'.Str::uuid())
            ->assertNotFound()
            ->assertJsonPath('message', 'Pagamento não encontrado.');
    }

    public function test_sem_token_nao_ha_pagamentos(): void
    {
        $this->getJson('/api/v1/admin/payment-orders?uuids=x')->assertStatus(401);
        $this->getJson('/api/v1/admin/payment-orders/x')->assertStatus(401);
    }
}
