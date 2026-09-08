<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O que o tecnico ve ao escolher competencias.
 *
 * Uma categoria desativada no backoffice deixou de existir para quem pede o
 * servico. Se continuar a aparecer aqui, o tecnico inscreve-se numa competencia
 * que nunca lhe vai gerar pedidos — e fica a espera de trabalho que nao chega,
 * sem perceber porque.
 */
class VendorOperationAreasActiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
    }

    private function areas(): array
    {
        $ativa = OperationArea::factory()->create(['is_active' => true]);
        $inativa = OperationArea::factory()->create(['is_active' => false]);

        ServicesType::factory()->create(['operation_area_id' => $ativa->id, 'is_active' => true]);
        $tipoInativo = ServicesType::factory()->create(['operation_area_id' => $ativa->id, 'is_active' => false]);
        ServicesType::factory()->create(['operation_area_id' => $inativa->id, 'is_active' => true]);

        return [$ativa, $inativa, $tipoInativo];
    }

    public function test_uma_categoria_desativada_nao_aparece_ao_tecnico(): void
    {
        [$ativa, $inativa] = $this->areas();

        $response = $this->getJson('/api/v1/vendor/services/operation-areas');

        $response->assertOk();
        $ids = array_column($response->json('data.operation_areas') ?? $response->json('data'), 'id');
        $this->assertContains($ativa->id, $ids);
        $this->assertNotContains($inativa->id, $ids);
    }

    public function test_um_trabalho_desativado_nao_aparece_dentro_da_categoria(): void
    {
        [$ativa, , $tipoInativo] = $this->areas();

        $response = $this->getJson('/api/v1/vendor/services/operation-areas');

        $areas = $response->json('data.operation_areas') ?? $response->json('data');
        $area = collect($areas)->firstWhere('id', $ativa->id);
        $this->assertNotContains($tipoInativo->id, array_column($area['services_types'], 'id'));
    }

    public function test_o_mesmo_vale_para_o_tecnico_autenticado(): void
    {
        // A segunda query do controlador (a que traz o preco do vendor) tinha o
        // mesmo problema, e e essa que serve quem ja tem conta.
        [$ativa, $inativa] = $this->areas();
        $vendor = Vendor::factory()->create();

        $response = $this->actingAs($vendor->user, 'api')->getJson('/api/v1/vendor/services/operation-areas');

        $ids = array_column($response->json('data.operation_areas') ?? $response->json('data'), 'id');
        $this->assertContains($ativa->id, $ids);
        $this->assertNotContains($inativa->id, $ids);
    }
}
