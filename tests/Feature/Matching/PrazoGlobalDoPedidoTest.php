<?php

namespace Tests\Feature\Matching;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Matching\MatchingCandidateLostEvent;
use App\Events\Matching\MatchingInvitationEvent;
use App\Events\Matching\MatchingRequestClosedEvent;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\Vendor;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * O pedido tem um fim conhecido desde que e criado.
 *
 * Antes nao havia tecto: o relogio do cliente so arrancava no PRIMEIRO ACEITE,
 * e ate la o pedido ficava aberto o tempo que a janela dos profissionais
 * permitisse — meia hora no agendado. So depois disso arrancavam os 30 minutos
 * de escolha. Uma hora, no pior caso, entre pedir e ter profissional
 * confirmado, com a primeira metade em silencio.
 *
 * Agora ha `request_deadline_seconds` a contar da criacao, igual para imediato
 * e agendado. As janelas por modo continuam a valer, mas nenhuma leva o pedido
 * para alem deste prazo.
 */
class PrazoGlobalDoPedidoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Os avisos ao profissional vao por websocket. Sem isto o teste tenta
        // falar com um Pusher que nao existe no ambiente de teste, e falha por
        // uma razao que nada tem a ver com o que esta a medir.
        Event::fake([
            MatchingInvitationEvent::class,
            MatchingRequestClosedEvent::class,
            MatchingCandidateLostEvent::class,
        ]);
    }

    private function emMatching(?string $criadoHa = null): Service
    {
        $service = Service::factory()->create(['status' => ServiceStatus::MATCHING]);

        if ($criadoHa) {
            $service->forceFill(['created_at' => now()->sub($criadoHa)])->saveQuietly();
        }

        return $service->refresh();
    }

    private function candidato(
        Service $service,
        CandidateStatus $status,
        ?CarbonInterface $respondidoEm = null,
        ?CarbonInterface $expiraEm = null,
    ): ServiceCandidate {
        return ServiceCandidate::create([
            'service_id' => $service->id,
            'vendor_id' => Vendor::factory()->create()->id,
            'rank' => 1,
            'wave' => 1,
            'status' => $status,
            'quoted_amount' => 5000,
            'quoted_amount_for_vendor' => 3750,
            'quoted_distance' => 3,
            'notified_at' => now()->subMinutes(1),
            'responded_at' => $respondidoEm,
            'expires_at' => $expiraEm,
        ]);
    }

    public function test_o_pedido_morre_ao_fim_do_prazo_mesmo_sem_ninguem_ter_respondido(): void
    {
        $service = $this->emMatching('4 minutes');

        $this->artisan('matching:advance')->assertSuccessful();

        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
    }

    public function test_o_pedido_morre_ao_fim_do_prazo_mesmo_com_aceites_a_espera_de_escolha(): void
    {
        // Este e o caso que a janela de escolha escondia: ela conta a partir do
        // primeiro aceite e podia empurrar o desfecho para muito depois. Um
        // cliente que nao escolheu em tres minutos nao esta a olhar para o
        // ecra, e quem disse que sim nao pode ficar presa a isso.
        $service = $this->emMatching('4 minutes');

        $this->candidato($service, CandidateStatus::ACCEPTED, now()->subMinutes(3));

        $this->artisan('matching:advance')->assertSuccessful();

        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
    }

    public function test_dentro_do_prazo_o_pedido_continua_vivo(): void
    {
        $service = $this->emMatching('30 seconds');
        // Com um convite ainda de pé: sem candidatos o pedido morreria por não
        // haver quem convidar, que é outra regra e mascararia esta.
        $this->candidato($service, CandidateStatus::NOTIFIED, null, now()->addMinutes(2));

        $this->artisan('matching:advance')->assertSuccessful();

        $this->assertSame(ServiceStatus::MATCHING, $service->refresh()->status);
    }
}
