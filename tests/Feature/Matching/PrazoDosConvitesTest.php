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
 * A FASE DE CONVITES tem um fim conhecido.
 *
 * Antes do primeiro sim nao ha relogio do cliente a correr: ha um pedido aberto
 * a espera de que alguem responda. Sem prazo nenhum, o unico fim possivel era o
 * esgotar das ondas — e no agendado a janela de resposta era de meia hora.
 *
 * O `request_deadline_seconds` fecha essa porta, a contar de quando o pedido
 * entra em selecao (num personalizado, do envio pelo backoffice).
 *
 * O QUE ELE JA NAO FAZ: cortar o prazo do cliente. Era um tecto unico por cima
 * das duas fases, e o que a fase dos convites gastasse saia do tempo de quem
 * tinha de escolher — no pior caso sobravam-lhe zero segundos. As duas fases
 * passaram a ter orcamentos proprios, em cadeia; o `DoisPrazosTest` prende essa
 * cadeia inteira, e este ficheiro so a primeira metade.
 *
 * Por isso e tambem uma rede de seguranca e nao uma promessa: esta muito acima
 * do que o calendario das ondas precisa, de proposito. Colado a esse calendario,
 * o atraso do cron — que corre ao minuto — cortava a janela da ultima onda e o
 * profissional convidado ao fim tinha menos tempo do que os outros.
 */
class PrazoDosConvitesTest extends TestCase
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

    public function test_o_pedido_morre_ao_fim_do_prazo_sem_ninguem_ter_respondido(): void
    {
        $service = $this->emMatching('11 minutes');
        // Com um convite ainda de pé: sem candidatos vivos o pedido morreria por
        // as ondas se esgotarem, que é outra regra e mascararia esta.
        $this->candidato($service, CandidateStatus::NOTIFIED, null, now()->addMinutes(2));

        $this->artisan('matching:advance')->assertSuccessful();

        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
    }

    /**
     * A FRONTEIRA, e a razão de este ficheiro ter mudado de nome.
     *
     * Havia aqui um teste a provar o contrário: que o prazo matava o pedido
     * mesmo com aceites à espera de escolha. Era a consequência do tecto único —
     * e era a consequência errada. Quem já disse sim não fica preso a uma
     * decisão que não chega, mas o relógio que a limita é o do CLIENTE, e conta
     * do primeiro sim. O tempo que os profissionais levaram a responder não sai
     * do tempo dele.
     */
    public function test_a_partir_do_primeiro_sim_este_prazo_deixa_de_contar(): void
    {
        // Onze minutos desde a criação: por este prazo, morto há muito.
        $service = $this->emMatching('11 minutes');

        $this->candidato($service, CandidateStatus::ACCEPTED, now()->subSeconds(30));

        $this->artisan('matching:advance')->assertSuccessful();

        $this->assertSame(
            ServiceStatus::MATCHING,
            $service->refresh()->status,
            'com um sim carimbado há 30 s, o cliente tem os minutos dele por inteiro',
        );
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
