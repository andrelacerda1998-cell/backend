<?php

namespace Tests\Feature\Matching;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Matching\MatchingCandidateLostEvent;
use App\Events\Matching\MatchingInvitationEvent;
use App\Events\Matching\MatchingRequestClosedEvent;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\User;
use App\Models\Vendor;
use App\Settings\MatchingSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * O prazo tem de chegar ao ecra onde a decisao se toma.
 *
 * O `expires_at` ja ia no `/matching/current` — o atalho no separador
 * "Pedidos" — mas nao no `/matching/{service}`, que e O ecra da escolha. O
 * cliente via a lista de profissionais sem nada a dizer-lhe que havia um
 * relogio a correr, e o pedido morria enquanto ele comparava precos com calma.
 *
 * Um prazo invisivel nao e um prazo.
 *
 * O `server_time` vai junto pela mesma razao de sempre: o limite e do servidor
 * e o `Date.now()` e do telemovel. Num prazo de cinco minutos, trinta segundos
 * de desvio sao um decimo do tempo.
 */
class OPrazoVaiNoEcraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MatchingSettings::fake([
            'shortlist_size' => 3,
            'wave_size' => 6,
            'wave_interval_seconds' => 45,
            'max_waves' => 3,
            'vendor_response_seconds_immediate' => 120,
            'vendor_response_seconds_scheduled' => 120,
            'request_deadline_seconds' => 600,
            'customer_choice_seconds' => 300,
            'customer_choice_seconds_scheduled' => 300,
            'customer_choice_seconds_custom' => 3600,
            'custom_review_alert_weekdays' => 1,
            'custom_review_deadline_weekdays' => 2,
            'checkout_seconds' => 300,
            'rating_bands' => [4.5, 4.0, 3.0],
            'new_vendor_min_ratings' => 5,
            'require_recent_activity_minutes' => 15,
            'max_radius_km' => 50,
        ]);

        Event::fake([
            MatchingInvitationEvent::class,
            MatchingRequestClosedEvent::class,
            MatchingCandidateLostEvent::class,
        ]);
        config(['broadcasting.default' => 'null']);
    }

    private function pedido(User $customer): Service
    {
        return Service::factory()->create([
            'customer_id' => $customer->id,
            'status' => ServiceStatus::MATCHING,
        ]);
    }

    private function ver(User $customer, Service $service): array
    {
        return $this->actingAs($customer, 'api')
            ->getJson("/api/v1/customer/services/matching/{$service->id}")
            ->assertSuccessful()
            ->json('data.service');
    }

    public function test_o_ecra_da_escolha_recebe_o_prazo_e_a_hora_do_servidor(): void
    {
        $customer = User::factory()->create(['is_test' => true]);
        $service = $this->pedido($customer);

        $service->forceFill(['candidates_ready_at' => now()->subSeconds(30)])->saveQuietly();

        ServiceCandidate::create([
            'service_id' => $service->id,
            'vendor_id' => Vendor::factory()->create()->id,
            'rank' => 1,
            'wave' => 1,
            'status' => CandidateStatus::ACCEPTED,
            'quoted_amount' => 5000,
            'quoted_amount_for_vendor' => 3750,
            'quoted_distance' => 3,
            'notified_at' => now()->subSeconds(40),
            'responded_at' => now()->subSeconds(30),
        ]);

        $payload = $this->ver($customer, $service->refresh());

        $this->assertNotNull($payload['expires_at'] ?? null, 'sem prazo, o ecrã não tem contador');
        $this->assertNotNull($payload['server_time'] ?? null, 'sem hora do servidor, a contagem usa o relógio do telemóvel');

        // Primeiro sim há 30 s + 300 s de janela => faltam 270.
        $this->assertEqualsWithDelta(
            now()->addSeconds(270)->timestamp,
            Carbon::parse($payload['expires_at'])->timestamp,
            2,
        );
    }

    /**
     * Antes do primeiro sim não há relógio do cliente a correr — ele ainda não
     * tem nada para decidir. Mandar um prazo aqui faria a barra aparecer
     * durante a procura, a contar tempo que ainda não é dele.
     */
    public function test_sem_ninguem_aceite_nao_vai_prazo_nenhum(): void
    {
        $customer = User::factory()->create(['is_test' => true]);
        $service = $this->pedido($customer);

        $payload = $this->ver($customer, $service);

        $this->assertNull($payload['expires_at']);
    }

    /**
     * O prazo que o ecrã da escolha mostra e o que o separador "Pedidos" mostra
     * têm de ser o MESMO número. Se divergissem, o cliente veria dois relógios
     * para a mesma coisa — e ao primeiro segundo de diferença deixa de
     * acreditar em ambos.
     */
    public function test_e_o_mesmo_prazo_que_o_separador_dos_pedidos_anuncia(): void
    {
        $customer = User::factory()->create(['is_test' => true]);
        $service = $this->pedido($customer);

        $service->forceFill(['candidates_ready_at' => now()->subSeconds(45)])->saveQuietly();

        ServiceCandidate::create([
            'service_id' => $service->id,
            'vendor_id' => Vendor::factory()->create()->id,
            'rank' => 1,
            'wave' => 1,
            'status' => CandidateStatus::ACCEPTED,
            'quoted_amount' => 5000,
            'quoted_amount_for_vendor' => 3750,
            'quoted_distance' => 3,
            'notified_at' => now()->subSeconds(60),
            'responded_at' => now()->subSeconds(45),
        ]);

        $noEcra = $this->ver($customer, $service->refresh())['expires_at'];

        $noSeparador = $this->actingAs($customer, 'api')
            ->getJson('/api/v1/customer/services/matching/current')
            ->assertSuccessful()
            ->json('data.request.expires_at');

        $this->assertNotNull($noSeparador);
        $this->assertSame(
            Carbon::parse($noEcra)->timestamp,
            Carbon::parse($noSeparador)->timestamp,
        );
    }
}
