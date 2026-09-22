<?php

namespace Tests\Feature\Endpoints;

use App\Models\User;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use RwInteractive\PayshopSdk\Models\PaymentMethod;
use Tests\TestCase;

/**
 * Cartões guardados.
 *
 * Os quatro endpoints de leitura/gestão não falam com o Payshop — mexem na
 * tabela local. O que interessa fixar é que o cartão de uma pessoa não é
 * acessível a outra, em nenhum dos quatro.
 *
 * O endpoint de ADICIONAR cartão fica de fora de propósito: chama mesmo o
 * gateway (Guzzle cru, que o `Http::fake()` não intercepta) e, por omissão,
 * contra o sandbox real do Paylands. Testá-lo aqui seria fazer pedidos a um
 * serviço externo a partir da suite.
 */
class ClienteMetodosDePagamentoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    private function cartao(User $dono): PaymentMethod
    {
        // `forceFill` e nao `create`: o `user_id` nao esta no `$fillable` do
        // modelo do SDK, e um `create` deixava-o em branco — o teste passava a
        // exercitar um cartao sem dono, que e o cenario que nao interessa.
        $cartao = (new PaymentMethod)->forceFill([
            'user_id' => $dono->id,
            'type' => 'card',
            'uuid' => 'uuid-'.$dono->id,
            'token' => 'tok-'.$dono->id,
            'brand' => 'VISA',
            'country' => 'PT',
            'holder' => 'Andre Lacerda',
            'bin' => '411111',
            'last4' => '1111',
            'expire_month' => '12',
            'expire_year' => '2030',
        ]);
        $cartao->save();

        return $cartao->refresh();
    }

    public function test_o_cliente_lista_os_seus_cartoes(): void
    {
        $customer = User::factory()->create();
        $this->cartao($customer);

        $r = $this->actingAs($customer, 'api')->getJson('/api/v1/customer/payment-methods');

        $r->assertOk();
        $this->assertCount(1, $r->json('data'));
    }

    public function test_a_lista_nao_traz_os_cartoes_de_outra_pessoa(): void
    {
        $this->cartao(User::factory()->create());

        $r = $this->actingAs(User::factory()->create(), 'api')->getJson('/api/v1/customer/payment-methods');

        $r->assertOk();
        $this->assertSame([], $r->json('data'));
    }

    public function test_o_cliente_ve_os_detalhes_do_seu_cartao(): void
    {
        $customer = User::factory()->create();
        $cartao = $this->cartao($customer);

        $this->actingAs($customer, 'api')
            ->getJson("/api/v1/customer/payment-methods/{$cartao->id}")
            ->assertOk();
    }

    public function test_um_cliente_nao_ve_o_cartao_de_outro(): void
    {
        $cartao = $this->cartao(User::factory()->create());

        // 404 e nao 403: confirmar que o cartao existe ja e dizer alguma
        // coisa sobre outra pessoa. E nao 500, que era o que dava.
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson("/api/v1/customer/payment-methods/{$cartao->id}")
            ->assertStatus(404);
    }

    public function test_o_cliente_define_o_cartao_por_omissao(): void
    {
        $customer = User::factory()->create();
        $cartao = $this->cartao($customer);

        $this->actingAs($customer, 'api')
            ->putJson("/api/v1/customer/payment-methods/{$cartao->id}")
            ->assertOk();

        $this->assertSame($cartao->id, $customer->refresh()->default_payment_method_id);
    }

    public function test_um_cliente_nao_define_como_seu_o_cartao_de_outro(): void
    {
        $cartao = $this->cartao(User::factory()->create());
        $intruso = User::factory()->create();

        $this->actingAs($intruso, 'api')
            ->putJson("/api/v1/customer/payment-methods/{$cartao->id}")
            ->assertStatus(404);

        $this->assertNull($intruso->refresh()->default_payment_method_id, 'nao pode ficar a apontar para o cartao de outra pessoa');
    }

    public function test_o_cliente_apaga_o_seu_cartao(): void
    {
        $customer = User::factory()->create();
        $cartao = $this->cartao($customer);

        $this->actingAs($customer, 'api')
            ->deleteJson("/api/v1/customer/payment-methods/{$cartao->id}")
            ->assertOk();

        $this->assertSoftDeleted('payshop_payment_methods', ['id' => $cartao->id]);
    }

    public function test_um_cliente_nao_apaga_o_cartao_de_outro(): void
    {
        $cartao = $this->cartao(User::factory()->create());

        $this->actingAs(User::factory()->create(), 'api')
            ->deleteJson("/api/v1/customer/payment-methods/{$cartao->id}")
            ->assertStatus(404);

        $this->assertDatabaseHas('payshop_payment_methods', ['id' => $cartao->id, 'deleted_at' => null]);
    }

    public function test_os_cartoes_exigem_autenticacao(): void
    {
        $cartao = $this->cartao(User::factory()->create());

        $this->getJson('/api/v1/customer/payment-methods')->assertStatus(401);
        $this->getJson("/api/v1/customer/payment-methods/{$cartao->id}")->assertStatus(401);
        $this->putJson("/api/v1/customer/payment-methods/{$cartao->id}")->assertStatus(401);
        $this->deleteJson("/api/v1/customer/payment-methods/{$cartao->id}")->assertStatus(401);
    }

    public function test_limpar_o_cartao_de_convidado_exige_autenticacao(): void
    {
        $this->postJson('/api/v1/customer/payment-methods/credit-card/flush-guest')->assertStatus(401);
    }
}
