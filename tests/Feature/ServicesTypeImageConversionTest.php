<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\OperationArea;
use App\Models\GeneralSettings\ServicesType;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A imagem do tipo de servico servida ao cliente.
 *
 * O catalogo de producao tem PNGs de ~2 MB por tipo. Uma categoria tem 26
 * tipos: servir o original em vez da conversao webp sao dezenas de MB por
 * ecra, em dados moveis, so para desenhar miniaturas.
 *
 * Os dois endpoints que alimentam o mesmo cartao tem de responder a mesma
 * imagem — foi por divergirem que isto passou despercebido.
 */
class ServicesTypeImageConversionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
    }

    private function tipoComImagem(OperationArea $area): ServicesType
    {
        $tipo = ServicesType::factory()->create([
            'operation_area_id' => $area->id,
            'is_active' => true,
            'is_popular' => true,
        ]);

        $tipo->addMedia(UploadedFile::fake()->image('catalogo.png', 1200, 1200))
            ->preservingOriginal()
            ->toMediaCollection('image');

        return $tipo;
    }

    public function test_os_dois_endpoints_servem_a_conversao_e_nao_o_original(): void
    {
        // A conversao webp esta marcada como ->queued(): sem ela ser gerada, os
        // dois endpoints caiem no original e o teste passava com ou sem a
        // correcao — sem provar nada.
        //
        // Desliga-se a fila NA MEDIA LIBRARY em vez de por `queue.default` a
        // sync: o `ScheduleSoftDeleteTest` corre logo antes (ordem alfabetica)
        // e chama `Queue::fake()`, e este teste chegou a falhar uma vez numa
        // corrida completa por depender do estado da fila. Assim nao depende.
        config([
            'media-library.queue_conversions_by_default' => false,
            'queue.default' => 'sync',
        ]);
        Storage::fake('public');
        $area = OperationArea::factory()->create(['is_active' => true]);
        $tipo = $this->tipoComImagem($area);

        $this->assertArrayHasKey(
            'webp',
            $tipo->getFirstMedia('image')->refresh()->generated_conversions ?? [],
            'a conversao webp nao foi gerada — o teste nao distinguiria nada'
        );

        $porArea = $this->getJson("/api/v1/common/services/operation-areas/{$area->id}/services-types")
            ->assertOk()->json('data.services.0.image');

        $destaques = $this->getJson('/api/v1/common/services/services-types/popular')
            ->assertOk()->json('data.services.0.image');

        $this->assertNotNull($porArea);
        $this->assertStringContainsString('webp', $porArea, 'a lista por area servia o original');
        $this->assertSame($destaques, $porArea);
    }
}
