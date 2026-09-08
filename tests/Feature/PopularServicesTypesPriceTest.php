<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O "Desde X €" dos destaques da Home.
 *
 * A Home desenha os mesmos cartoes a partir de duas fontes: enquanto ninguem
 * marcar destaques no backoffice, a app cai numa lista curada em codigo e vai
 * buscar os servicos por area — que devolve `starts_from`. Assim que alguem
 * marcar `is_popular`, este endpoint passa a mandar.
 *
 * Sem `starts_from` aqui, esse dia e o dia em que os precos desaparecem da
 * Home sem ninguem ter mexido na app.
 */
class PopularServicesTypesPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
    }

    public function test_os_destaques_trazem_o_preco_inicial(): void
    {
        $area = OperationArea::factory()->create(['is_active' => true]);
        $tipo = ServicesType::factory()->create([
            'operation_area_id' => $area->id,
            'is_active' => true,
            'is_popular' => true,
            'starts_from' => 4000,
        ]);

        $this->getJson('/api/v1/common/services/services-types/popular')
            ->assertOk()
            ->assertJsonPath('data.services.0.id', $tipo->id)
            ->assertJsonPath('data.services.0.starts_from', 4000);
    }

    public function test_os_dois_endpoints_concordam_no_preco(): void
    {
        $area = OperationArea::factory()->create(['is_active' => true]);
        ServicesType::factory()->create([
            'operation_area_id' => $area->id,
            'is_active' => true,
            'is_popular' => true,
            'starts_from' => 7500,
        ]);

        $destaques = $this->getJson('/api/v1/common/services/services-types/popular')
            ->assertOk()->json('data.services.0.starts_from');

        $porArea = $this->getJson("/api/v1/common/services/operation-areas/{$area->id}/services-types")
            ->assertOk()->json('data.services.0.starts_from');

        $this->assertSame($porArea, $destaques);
    }
}
