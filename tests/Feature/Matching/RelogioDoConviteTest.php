<?php

namespace Tests\Feature\Matching;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * O convite conta pelo relógio de quem manda o prazo.
 *
 * Era o único payload de serviço da app do técnico sem `server_time`: o prazo
 * vinha do servidor e o contador comparava-o com o relógio do telemóvel. Numa
 * janela de 60 segundos, 30 segundos de desvio comiam metade do tempo visível.
 *
 * E ao chegar a zero o sintoma não era um cartão que desaparece limpo: a Home
 * ficava com "N pedidos · 0s" a vermelho, o ecrã abria vazio e o botão agia
 * sobre uma lista invisível.
 */
class RelogioDoConviteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    public function test_o_convite_diz_que_horas_sao_no_servidor(): void
    {
        $vendor = Vendor::factory()->create();
        $service = Service::factory()->create([
            'services_type_id' => ServicesType::factory(),
            'status' => ServiceStatus::MATCHING,
        ]);

        ServiceCandidate::query()->create([
            'service_id' => $service->id,
            'vendor_id' => $vendor->id,
            'rank' => 1,
            'wave' => 1,
            // O index só devolve convites NOTIFIED — e já filtra os expirados.
            'status' => CandidateStatus::NOTIFIED,
            'notified_at' => Carbon::now()->subSeconds(10),
            'expires_at' => Carbon::now()->addSeconds(50),
        ]);

        $convites = $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/vendor/services/matching')
            ->assertSuccessful()
            ->json('data');

        $convite = collect($convites)->first() ?? collect($convites['invitations'] ?? [])->first();

        $this->assertNotNull($convite, 'o convite tem de aparecer na lista');
        $this->assertArrayHasKey('server_time', $convite);
        $this->assertNotNull($convite['server_time']);

        // As três referências têm de vir da mesma máquina: início da janela,
        // fim da janela, e o "agora" com que se comparam.
        $this->assertNotNull($convite['notified_at']);
        $this->assertNotNull($convite['expires_at']);
        $this->assertTrue(
            Carbon::parse($convite['server_time'])->between(
                Carbon::parse($convite['notified_at']),
                Carbon::parse($convite['expires_at']),
            ),
            'o "agora" do servidor tem de cair dentro da janela que ele próprio abriu',
        );
    }
}
