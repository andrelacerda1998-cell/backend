<?php

namespace Tests\Feature;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tirar um serviço do catálogo sem partir o histórico: um tipo que já tem
 * pedidos não se apaga (os pedidos antigos ficavam sem nome), desativa-se.
 */
class AdminCatalogoApagarEDesativarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        config(['services.admin_api.token' => 'a-valid-token']);
    }

    private function api(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    private function pedidoDo(ServicesType $tipo, bool $teste = false): void
    {
        DB::table('services')->insert([
            'services_type_id' => $tipo->id,
            'status' => ServiceStatus::CLOSED->value,
            'payment_status' => PaymentStatus::PAID->value,
            'is_test' => $teste,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_um_tipo_com_pedidos_nao_se_apaga(): void
    {
        $tipo = ServicesType::factory()->create();
        $this->pedidoDo($tipo);

        $this->api()->deleteJson("/api/v1/admin/services-types/{$tipo->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Este serviço já tem pedidos. Desativa-o em vez de o apagar.');

        $this->assertNotSoftDeleted('services_types', ['id' => $tipo->id]);
    }

    public function test_um_tipo_sem_pedidos_reais_apaga_se(): void
    {
        $semPedidos = ServicesType::factory()->create();
        $soComTestes = ServicesType::factory()->create();
        $this->pedidoDo($soComTestes, teste: true);

        $this->api()->deleteJson("/api/v1/admin/services-types/{$semPedidos->id}")->assertOk()
            ->assertJsonPath('data.deleted', true);
        $this->api()->deleteJson("/api/v1/admin/services-types/{$soComTestes->id}")->assertOk();

        $this->assertSoftDeleted('services_types', ['id' => $semPedidos->id]);
        $this->assertSoftDeleted('services_types', ['id' => $soComTestes->id]);
    }

    public function test_desativa_e_reativa(): void
    {
        $tipo = ServicesType::factory()->create();
        $this->pedidoDo($tipo);

        $this->api()->putJson("/api/v1/admin/services-types/{$tipo->id}", ['is_active' => false])->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.services_count', 1);
        $this->assertFalse((bool) $tipo->fresh()->is_active);

        $this->api()->putJson("/api/v1/admin/services-types/{$tipo->id}", ['is_active' => true])->assertOk()
            ->assertJsonPath('data.is_active', true);
        $this->assertTrue((bool) $tipo->fresh()->is_active);
    }

    public function test_is_active_tem_de_ser_verdadeiro_ou_falso(): void
    {
        $tipo = ServicesType::factory()->create();

        $this->api()->putJson("/api/v1/admin/services-types/{$tipo->id}", ['is_active' => 'talvez'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);
    }

    /** Editar outra coisa não mexe no estado. */
    public function test_editar_sem_is_active_nao_o_muda(): void
    {
        $tipo = ServicesType::factory()->create(['is_active' => false]);

        $this->api()->putJson("/api/v1/admin/services-types/{$tipo->id}", ['time' => 45])->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.time', 45);
    }

    public function test_a_lista_e_a_ficha_trazem_o_estado_e_os_pedidos_reais(): void
    {
        $tipo = ServicesType::factory()->create(['is_active' => false]);
        $this->pedidoDo($tipo);
        $this->pedidoDo($tipo);
        $this->pedidoDo($tipo, teste: true);

        $item = collect($this->api()->getJson('/api/v1/admin/services-types?per_page=100')->assertOk()->json('data.items'))
            ->firstWhere('id', $tipo->id);

        $this->assertFalse($item['is_active']);
        $this->assertSame(2, $item['services_count']);

        $this->api()->getJson("/api/v1/admin/services-types/{$tipo->id}")->assertOk()
            ->assertJsonPath('data.id', $tipo->id)
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.services_count', 2);

        $this->api()->getJson('/api/v1/admin/services-types/999999')->assertNotFound();
    }
}
