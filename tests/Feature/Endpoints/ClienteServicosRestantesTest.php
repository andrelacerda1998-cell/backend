<?php

namespace Tests\Feature\Endpoints;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * O resto do lado do cliente: fechar, estado do pagamento, cancelar um 3DS
 * pendente, histórico, fotos e rota.
 *
 * Fechar um serviço é o que liberta o dinheiro para o profissional — e era dos
 * poucos caminhos de dinheiro sem um teste que passasse pela rota.
 */
class ClienteServicosRestantesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    /** @return array{0: User, 1: Service} */
    private function servico(ServiceStatus $status, array $extra = []): array
    {
        $customer = User::factory()->create();
        $service = Service::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'vendor_id' => Vendor::factory()->create()->id,
            'status' => $status,
        ], $extra));

        return [$customer, $service->refresh()];
    }

    private function intruso(): User
    {
        return User::factory()->create();
    }

    // ------------------------------------------------------------- fechar

    public function test_nao_se_fecha_um_servico_que_ainda_nao_terminou(): void
    {
        [$customer, $service] = $this->servico(ServiceStatus::ACCEPTED);

        // 409 e nao 500: o serviço só se fecha depois de o técnico o dar por
        // terminado. Era esta guarda que respondia como avaria.
        $this->actingAs($customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/close")
            ->assertStatus(409);

        $this->assertSame(ServiceStatus::ACCEPTED, $service->refresh()->status);
    }

    public function test_fechar_duas_vezes_nao_paga_duas_vezes(): void
    {
        [$customer, $service] = $this->servico(ServiceStatus::CLOSED);

        $this->actingAs($customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/close")
            ->assertStatus(409);
    }

    public function test_um_cliente_nao_fecha_o_servico_de_outro(): void
    {
        [, $service] = $this->servico(ServiceStatus::FINISHED);

        $this->actingAs($this->intruso(), 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/close")
            ->assertStatus(404);

        $this->assertSame(ServiceStatus::FINISHED, $service->refresh()->status);
    }

    public function test_fechar_exige_autenticacao(): void
    {
        [, $service] = $this->servico(ServiceStatus::FINISHED);

        $this->postJson("/api/v1/customer/services/{$service->id}/close")->assertStatus(401);
    }

    // ------------------------------------------------ estado do pagamento

    /**
     * Já pago responde 200 sem voltar a correr nada.
     *
     * A app faz polling a este endpoint de 10 em 10 segundos enquanto espera.
     * Sem a guarda de idempotência, cada consulta repetia o fluxo de pagamento
     * e disparava outro push ao técnico — o mesmo serviço a ser anunciado
     * dezenas de vezes.
     */
    public function test_um_servico_ja_pago_responde_sem_repetir_o_fluxo(): void
    {
        [$customer, $service] = $this->servico(ServiceStatus::ACCEPTED, [
            'payment_status' => PaymentStatus::PAID,
        ]);

        $this->actingAs($customer, 'api')
            ->getJson("/api/v1/customer/services/{$service->id}/payment-status")
            ->assertOk();

        Notification::assertNothingSent();
    }

    /**
     * Ainda por pagar responde 400 de propósito: é esse código que mantém a
     * app no ecrã de espera em vez de a mandar seguir.
     */
    public function test_um_pagamento_por_concluir_nao_responde_ok(): void
    {
        [$customer, $service] = $this->servico(ServiceStatus::PENDING, [
            'payment_status' => PaymentStatus::PENDING,
        ]);

        $this->actingAs($customer, 'api')
            ->getJson("/api/v1/customer/services/{$service->id}/payment-status")
            ->assertStatus(400);
    }

    public function test_um_cliente_nao_consulta_o_pagamento_de_outro(): void
    {
        [, $service] = $this->servico(ServiceStatus::PENDING);

        $r = $this->actingAs($this->intruso(), 'api')
            ->getJson("/api/v1/customer/services/{$service->id}/payment-status");

        $this->assertContains($r->status(), [403, 404], 'o estado do pagamento de outra pessoa nao sai daqui');
    }

    public function test_estado_do_pagamento_exige_autenticacao(): void
    {
        [, $service] = $this->servico(ServiceStatus::PENDING);

        $this->getJson("/api/v1/customer/services/{$service->id}/payment-status")->assertStatus(401);
    }

    // -------------------------------------------------- cancelar 3DS

    /**
     * Este endpoint é idempotente de propósito: a recusa do banco pode já ter
     * marcado o serviço CANCELED, e o botão do ecrã de espera não pode
     * responder erro por isso — senão só produz falha.
     */
    public function test_cancelar_um_3ds_ja_cancelado_responde_ok(): void
    {
        [$customer, $service] = $this->servico(ServiceStatus::CANCELED);

        $this->actingAs($customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/cancel-pending-3ds")
            ->assertOk();
    }

    public function test_um_cliente_nao_cancela_o_3ds_de_outro(): void
    {
        [, $service] = $this->servico(ServiceStatus::PENDING_3DS);

        $this->actingAs($this->intruso(), 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/cancel-pending-3ds")
            ->assertStatus(404);
    }

    public function test_cancelar_3ds_exige_autenticacao(): void
    {
        [, $service] = $this->servico(ServiceStatus::PENDING_3DS);

        $this->postJson("/api/v1/customer/services/{$service->id}/cancel-pending-3ds")->assertStatus(401);
    }

    // ---------------------------------------------------------- historico

    public function test_o_historico_do_cliente_responde(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson('/api/v1/customer/services/history', [])
            ->assertOk();
    }

    public function test_o_historico_do_cliente_exige_autenticacao(): void
    {
        $this->postJson('/api/v1/customer/services/history', [])->assertStatus(401);
    }

    // --------------------------------------------------------- em seleção

    public function test_o_pedido_em_selecao_responde_mesmo_sem_nenhum(): void
    {
        // Sem pedido em curso tem de responder 200 com vazio, e não 404: a
        // home da app chama isto sempre, inclusive de conta acabada de criar.
        $this->actingAs(User::factory()->create(), 'api')
            ->getJson('/api/v1/customer/services/matching/current')
            ->assertOk();
    }

    public function test_pedido_em_selecao_exige_autenticacao(): void
    {
        $this->getJson('/api/v1/customer/services/matching/current')->assertStatus(401);
    }

    // --------------------------------------------------------------- rota

    public function test_ao_dono_a_rota_falha_a_calcular_mas_nao_e_negada(): void
    {
        [$customer, $service] = $this->servico(ServiceStatus::ACCEPTED);

        $r = $this->actingAs($customer, 'api')
            ->getJson("/api/v1/customer/services/{$service->id}/route");

        $this->assertNotContains($r->status(), [403, 404]);
        $this->assertContains($r->status(), [200, 400]);
    }

    public function test_a_rota_de_um_servico_alheio_da_404(): void
    {
        [, $service] = $this->servico(ServiceStatus::ACCEPTED);

        $this->actingAs($this->intruso(), 'api')
            ->getJson("/api/v1/customer/services/{$service->id}/route")
            ->assertStatus(404);
    }

    // -------------------------------------------------------------- fotos

    public function test_anexar_foto_exige_autenticacao(): void
    {
        $this->postJson('/api/v1/customer/services/photos', [])->assertStatus(401);
    }

    public function test_anexar_foto_sem_ficheiro_e_recusado(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson('/api/v1/customer/services/photos', [])
            ->assertStatus(422);
    }

    public function test_apagar_foto_exige_autenticacao(): void
    {
        $this->deleteJson('/api/v1/customer/services/photos/1')->assertStatus(401);
    }
}
