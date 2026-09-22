<?php

namespace Tests\Feature\Endpoints;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Voucher;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * O que o cliente pode fazer a um servico seu — e o que nao pode fazer ao de
 * outra pessoa.
 *
 * Cancelar, fechar e avaliar decidem o destino do dinheiro; validar um cupao
 * decide quanto e que ele chega a pagar. Nenhum destes endpoints tinha teste.
 */
class ClienteCicloDeVidaEcuponsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    /** @return array{0: User, 1: Service} */
    private function servico(ServiceStatus $status = ServiceStatus::PENDING, array $extra = []): array
    {
        $customer = User::factory()->create();
        $service = Service::factory()->create(array_merge([
            'customer_id' => $customer->id,
            'vendor_id' => Vendor::factory()->create()->id,
            'status' => $status,
        ], $extra));

        return [$customer, $service->refresh()];
    }

    // ----------------------------------------------------------- cancelar

    public function test_o_cliente_cancela_um_pedido_seu(): void
    {
        [$customer, $service] = $this->servico(ServiceStatus::PENDING, [
            'payment_status' => PaymentStatus::PAID,
        ]);

        $this->actingAs($customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/cancel")
            ->assertOk();

        $this->assertContains($service->refresh()->status, [
            ServiceStatus::CANCELED,
            ServiceStatus::CANCELED_MBWAY,
        ]);
    }

    public function test_nao_se_cancela_um_pedido_que_ja_esta_cancelado(): void
    {
        [$customer, $service] = $this->servico(ServiceStatus::CANCELED);

        $this->actingAs($customer, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/cancel")
            ->assertStatus(422);
    }

    public function test_um_cliente_nao_cancela_o_pedido_de_outro(): void
    {
        [, $service] = $this->servico(ServiceStatus::PENDING);
        $intruso = User::factory()->create();

        $this->actingAs($intruso, 'api')
            ->postJson("/api/v1/customer/services/{$service->id}/cancel")
            ->assertStatus(404);

        $this->assertSame(ServiceStatus::PENDING, $service->refresh()->status);
    }

    public function test_cancelar_exige_autenticacao(): void
    {
        [, $service] = $this->servico();

        $this->postJson("/api/v1/customer/services/{$service->id}/cancel")->assertStatus(401);
    }

    // ------------------------------------------------------------ avaliar

    public function test_o_cliente_avalia_e_comenta(): void
    {
        [$customer, $service] = $this->servico(ServiceStatus::CLOSED);

        $this->actingAs($customer, 'api')
            ->putJson("/api/v1/customer/services/{$service->id}/rate", [
                'rate' => 5,
                'comment' => 'Chegou a horas e explicou tudo.',
            ])
            ->assertOk();

        $service->refresh();
        $this->assertSame(5, (int) $service->rating_by_customer);
        $this->assertSame('Chegou a horas e explicou tudo.', $service->rating_comment_by_customer);
    }

    public function test_avaliar_duas_vezes_e_conflito_e_nao_erro_de_servidor(): void
    {
        [$customer, $service] = $this->servico(ServiceStatus::CLOSED, ['rating_by_customer' => 4]);

        $this->actingAs($customer, 'api')
            ->putJson("/api/v1/customer/services/{$service->id}/rate", ['rate' => 1])
            ->assertStatus(409);

        $this->assertSame(4, (int) $service->refresh()->rating_by_customer);
    }

    public function test_um_cliente_nao_avalia_o_servico_de_outro(): void
    {
        [, $service] = $this->servico(ServiceStatus::CLOSED);

        $this->actingAs(User::factory()->create(), 'api')
            ->putJson("/api/v1/customer/services/{$service->id}/rate", ['rate' => 1])
            ->assertStatus(404);

        $this->assertNull($service->refresh()->rating_by_customer);
    }

    public function test_a_nota_do_cliente_tem_limites(): void
    {
        [$customer, $service] = $this->servico(ServiceStatus::CLOSED);

        $this->actingAs($customer, 'api')
            ->putJson("/api/v1/customer/services/{$service->id}/rate", ['rate' => 9])
            ->assertStatus(422);
    }

    // ------------------------------------------------------------- cupoes

    private function cupao(array $extra = []): Voucher
    {
        return Voucher::create(array_merge([
            'name' => 'PIQUET10',
            'discount_percentage' => 10,
            'is_active' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'max_uses' => 100,
            'valid_services' => ['immediate', 'scheduled'],
        ], $extra));
    }

    public function test_um_cupao_valido_e_aceite(): void
    {
        $this->cupao();
        $tipo = ServicesType::factory()->create();

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson('/api/v1/customer/vouchers/validate', [
                'voucher_name' => 'PIQUET10',
                'service_type' => $tipo->id,
            ])
            ->assertOk();
    }

    public function test_um_cupao_que_nao_existe_da_404(): void
    {
        $tipo = ServicesType::factory()->create();

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson('/api/v1/customer/vouchers/validate', [
                'voucher_name' => 'NAO-EXISTE',
                'service_type' => $tipo->id,
            ])
            ->assertStatus(404);
    }

    public function test_um_cupao_desativado_e_recusado(): void
    {
        $this->cupao(['is_active' => false]);
        $tipo = ServicesType::factory()->create();

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson('/api/v1/customer/vouchers/validate', [
                'voucher_name' => 'PIQUET10',
                'service_type' => $tipo->id,
            ])
            ->assertStatus(400);
    }

    public function test_um_cupao_fora_de_validade_e_recusado(): void
    {
        $this->cupao(['end_date' => now()->subDay()]);
        $tipo = ServicesType::factory()->create();

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson('/api/v1/customer/vouchers/validate', [
                'voucher_name' => 'PIQUET10',
                'service_type' => $tipo->id,
            ])
            ->assertStatus(400);
    }

    public function test_um_cupao_so_para_agendados_nao_serve_um_imediato(): void
    {
        $this->cupao(['valid_services' => ['scheduled']]);
        $tipo = ServicesType::factory()->create();

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson('/api/v1/customer/vouchers/validate', [
                'voucher_name' => 'PIQUET10',
                'service_type' => $tipo->id,
                'is_scheduled' => false,
            ])
            ->assertStatus(400);
    }

    public function test_validar_cupao_exige_nome_e_tipo_de_servico(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson('/api/v1/customer/vouchers/validate', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['voucher_name', 'service_type']);
    }

    public function test_validar_cupao_exige_autenticacao(): void
    {
        $this->postJson('/api/v1/customer/vouchers/validate', ['voucher_name' => 'X'])
            ->assertStatus(401);
    }
}
