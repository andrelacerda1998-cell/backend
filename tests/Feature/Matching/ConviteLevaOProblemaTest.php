<?php

namespace Tests\Feature\Matching;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * O convite leva o que o cliente escreveu sobre o problema.
 *
 * Sem isto o técnico via o tipo de serviço, o valor e a distância — e decidia
 * às cegas se aquilo lhe dava meia hora ou uma tarde. As notas já eram
 * guardadas ao abrir o pedido; o que faltava era chegarem a quem decide.
 */
class ConviteLevaOProblemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    private function convitePara(Vendor $vendor, ?string $notas): ServiceCandidate
    {
        $service = Service::factory()->create([
            'customer_id' => User::factory()->create()->id,
            'services_type_id' => ServicesType::factory(),
            'status' => ServiceStatus::MATCHING,
            'customer_notes' => $notas,
        ]);

        return ServiceCandidate::query()->create([
            'service_id' => $service->id,
            'vendor_id' => $vendor->id,
            'rank' => 1,
            'wave' => 1,
            'status' => CandidateStatus::NOTIFIED,
            'quoted_amount' => 5333,
            'quoted_amount_for_vendor' => 4000,
            'quoted_distance' => 2.4,
            'notified_at' => now(),
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    public function test_o_convite_mostra_o_que_o_cliente_escreveu(): void
    {
        $vendor = Vendor::factory()->create();
        $this->convitePara($vendor, 'A torneira da cozinha pinga há dois dias e já molhou o armário.');

        $convites = $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/vendor/services/matching')
            ->assertSuccessful()
            ->json('data');

        $this->assertSame(
            'A torneira da cozinha pinga há dois dias e já molhou o armário.',
            $convites[0]['customer_notes'],
        );
    }

    public function test_sem_notas_o_campo_vem_vazio_e_nao_em_falta(): void
    {
        // O campo é opcional de propósito — não trava quem só quer carregar em
        // "Pedir agora". Mas tem de EXISTIR no payload: a app decide o que
        // desenhar por ele, e uma chave em falta não é a mesma coisa que uma
        // chave vazia.
        $vendor = Vendor::factory()->create();
        $this->convitePara($vendor, null);

        $convites = $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/vendor/services/matching')
            ->assertSuccessful()
            ->json('data');

        $this->assertArrayHasKey('customer_notes', $convites[0]);
        $this->assertNull($convites[0]['customer_notes']);
    }
}
