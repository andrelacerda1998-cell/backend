<?php

namespace Tests\Feature;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\OperationArea;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * As ações sobre um pedido na API de admin (despachar, fechar, tentar cobrar,
 * desistir e devolver) e a leitura de um cliente e de um técnico pelo id.
 */
class AdminAcoesDoPedidoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        config(['services.admin_api.token' => 'a-valid-token']);
        Notification::fake();
        Queue::fake(); // evita o job real de faturação (InvoiceXpress) disparado ao fechar o serviço
    }

    private function api(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    private function personalizado(): Service
    {
        return Service::factory()->create([
            'vendor_id' => null,
            'services_type_id' => null,
            'is_custom' => true,
            'custom_description' => 'Montar um roupeiro.',
            'status' => ServiceStatus::PENDING_REVIEW,
            'payment_status' => PaymentStatus::PENDING,
            'amount' => null,
            'amount_for_vendor' => null,
        ]);
    }

    public function test_sem_token_nao_ha_acoes(): void
    {
        $s = $this->personalizado();
        $this->postJson("/api/v1/admin/services/{$s->id}/despachar", ['minutos' => 60, 'areas' => [1]])
            ->assertStatus(401);
    }

    public function test_despachar_um_personalizado_sem_ninguem_elegivel_falha_ja_e_diz_quantos(): void
    {
        $area = OperationArea::factory()->create();
        $s = $this->personalizado();

        $r = $this->api()->postJson("/api/v1/admin/services/{$s->id}/despachar", ['minutos' => 90, 'areas' => [$area->id]])
            ->assertOk();

        $this->assertSame(0, $r->json('data.convidados'));
        $s->refresh();
        $this->assertSame(90, $s->custom_duration_minutes);
        $this->assertNotNull($s->custom_dispatched_at);
        $this->assertSame(ServiceStatus::MATCHING_FAILED, $s->status);
        $this->assertSame([$area->id], $s->operationAreas()->pluck('operation_areas.id')->all());
    }

    public function test_nao_se_despacha_duas_vezes_nem_um_pedido_normal(): void
    {
        $area = OperationArea::factory()->create();
        $normal = Service::factory()->create(['status' => ServiceStatus::PENDING_REVIEW, 'is_custom' => false]);
        $this->api()->postJson("/api/v1/admin/services/{$normal->id}/despachar", ['minutos' => 60, 'areas' => [$area->id]])
            ->assertStatus(409);

        $s = $this->personalizado();
        $s->update(['status' => ServiceStatus::MATCHING]);
        $this->api()->postJson("/api/v1/admin/services/{$s->id}/despachar", ['minutos' => 60, 'areas' => [$area->id]])
            ->assertStatus(409);
    }

    public function test_despachar_valida_a_duracao_e_as_categorias(): void
    {
        $s = $this->personalizado();
        $this->api()->postJson("/api/v1/admin/services/{$s->id}/despachar", ['minutos' => 5, 'areas' => [1]])
            ->assertStatus(422);
        $this->api()->postJson("/api/v1/admin/services/{$s->id}/despachar", ['minutos' => 60, 'areas' => [999999]])
            ->assertStatus(422);
        $this->assertSame(ServiceStatus::PENDING_REVIEW, $s->refresh()->status);
    }

    public function test_tentar_cobrar_fecha_quando_o_dinheiro_ja_esta_seguro(): void
    {
        $s = Service::factory()->create([
            'status' => ServiceStatus::CLOSED_PENDING_PAYMENT,
            'payment_status' => PaymentStatus::PAID,
            'payment_order_id' => null,
        ]);

        $this->api()->postJson("/api/v1/admin/services/{$s->id}/tentar-cobrar")
            ->assertOk()
            ->assertJsonPath('data.status', ServiceStatus::CLOSED->value);
    }

    public function test_tentar_cobrar_e_desistir_so_valem_para_pagamentos_por_cobrar(): void
    {
        $s = Service::factory()->create(['status' => ServiceStatus::SCHEDULED]);
        $this->api()->postJson("/api/v1/admin/services/{$s->id}/tentar-cobrar")->assertStatus(409);
        $this->api()->postJson("/api/v1/admin/services/{$s->id}/desistir-e-devolver")->assertStatus(409);
        $this->api()->postJson("/api/v1/admin/services/{$s->id}/fechar")->assertStatus(409);
        $this->assertSame(ServiceStatus::SCHEDULED, $s->refresh()->status);
    }

    public function test_desistir_e_devolver_cancela_sem_pagar_ao_tecnico(): void
    {
        $s = Service::factory()->create([
            'status' => ServiceStatus::CLOSED_PENDING_PAYMENT,
            'payment_status' => PaymentStatus::PENDING,
            'payment_order_id' => null,
        ]);

        $this->api()->postJson("/api/v1/admin/services/{$s->id}/desistir-e-devolver")
            ->assertOk()
            ->assertJsonPath('data.status', ServiceStatus::CANCELED->value);
    }

    public function test_fechar_um_terminado_cobra_e_fecha(): void
    {
        $s = Service::factory()->create([
            'status' => ServiceStatus::FINISHED,
            'payment_status' => PaymentStatus::PAID,
            'payment_order_id' => null,
        ]);

        $this->api()->postJson("/api/v1/admin/services/{$s->id}/fechar")
            ->assertOk()
            ->assertJsonPath('data.status', ServiceStatus::CLOSED->value);
    }

    public function test_um_cliente_e_um_tecnico_pelo_id_mesmo_bloqueados(): void
    {
        $cliente = User::factory()->create(['first_name' => 'Marta']);
        $cliente->delete();
        $this->api()->getJson("/api/v1/admin/customers/{$cliente->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $cliente->id);

        $tecnico = Vendor::factory()->create();
        $tecnico->delete();
        $this->api()->getJson("/api/v1/admin/vendors/{$tecnico->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $tecnico->id);

        $this->api()->getJson('/api/v1/admin/customers/999999')->assertStatus(404);
    }

    public function test_as_leituras_com_nome_continuam_a_chegar_as_suas_rotas(): void
    {
        $this->api()->getJson('/api/v1/admin/customers/metrics')->assertOk();
        $this->api()->getJson('/api/v1/admin/vendors/metrics')->assertOk();
    }

    public function test_pesquisar_clientes_pelo_telefone(): void
    {
        $c = User::factory()->create(['phone_number' => '+351912345678']);
        $r = $this->api()->getJson('/api/v1/admin/customers?search=912345678')->assertOk();
        $this->assertContains($c->id, collect($r->json('data.items'))->pluck('id')->all());
    }
}
