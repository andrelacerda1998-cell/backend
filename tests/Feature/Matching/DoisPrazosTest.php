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
use App\Services\Matching\MatchingService;
use App\Settings\MatchingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Duas fases, dois prazos, em cadeia.
 *
 *   1. o profissional tem 120 s para dizer se tem interesse;
 *   2. o cliente tem 5 minutos, do PRIMEIRO SIM, para escolher E pagar.
 *
 * Antes era um tecto unico de 180 s por cima das duas fases, a contar da
 * criacao. Aritmetica: o que a fase dos convites gastasse saia do prazo do
 * cliente, e uma janela de resposta larga consumia-o inteiro — no pior caso
 * sobravam-lhe zero segundos. As definicoes de escolha foram todas postas a 180
 * na migracao `alinhar_prazos_com_o_tecto` exactamente por isso: para deixarem
 * de prometer tempo que o tecto nao dava. Eram tectos com nome de prazo.
 *
 * O que este ficheiro prende:
 *
 *   · o prazo do cliente conta do primeiro sim e NAO leva desconto do tempo que
 *     os profissionais levaram a responder;
 *   · cobre escolher E pagar — escolher no ultimo segundo nao da um relogio
 *     novo por cima;
 *   · enquanto ninguem aceitou continua a haver um fim conhecido, que e o prazo
 *     da fase de convites;
 *   · o `checkout_seconds` sobrevive como TECTO da fase de pagamento: encurta-o
 *     e ele volta a cortar primeiro.
 *
 * A janela dos 120 s do profissional prova-se no `MatchingFlowTest`, que e onde
 * vive o fixture capaz de convidar gente a serio.
 */
class DoisPrazosTest extends TestCase
{
    use RefreshDatabase;

    /** Cinco minutos, em segundos. O numero aparece muitas vezes. */
    private const CINCO_MINUTOS = 300;

    protected function setUp(): void
    {
        parent::setUp();

        MatchingSettings::fake($this->definicoes());

        // Todos implementam ShouldBroadcast: sem isto o teste tenta falar com um
        // Pusher que nao existe e falha por uma razao que nada tem a ver com o
        // que esta a medir.
        Event::fake([
            MatchingInvitationEvent::class,
            MatchingRequestClosedEvent::class,
            MatchingCandidateLostEvent::class,
        ]);
    }

    /**
     * TODAS as propriedades de `MatchingSettings`, com os valores de producao.
     *
     * O `fake()` do Spatie nao e parcial: uma propriedade que falte cai para a
     * base de dados — que na CI tem outro nome e faz o teste rebentar por um
     * motivo que nao e o dele.
     */
    private function definicoes(array $sobrepor = []): array
    {
        return array_merge([
            'shortlist_size' => 3,
            'wave_size' => 6,
            'wave_interval_seconds' => 45,
            'max_waves' => 3,
            'vendor_response_seconds_immediate' => 120,
            'vendor_response_seconds_scheduled' => 120,
            'request_deadline_seconds' => 600,
            'customer_choice_seconds' => self::CINCO_MINUTOS,
            'customer_choice_seconds_scheduled' => self::CINCO_MINUTOS,
            'customer_choice_seconds_custom' => 3600,
            'custom_review_alert_weekdays' => 1,
            'custom_review_deadline_weekdays' => 2,
            'checkout_seconds' => 300,
            'rating_bands' => [4.5, 4.0, 3.0],
            'new_vendor_min_ratings' => 5,
            'require_recent_activity_minutes' => 15,
            'max_radius_km' => 50,
        ], $sobrepor);
    }

    /** Um pedido de catalogo em selecao, criado ha N segundos. */
    private function pedido(int $criadoHaSegundos, ServiceStatus $status = ServiceStatus::MATCHING): Service
    {
        $service = Service::factory()->create(['status' => $status]);

        $service->forceFill(['created_at' => now()->subSeconds($criadoHaSegundos)])->saveQuietly();

        return $service->refresh();
    }

    /** Um sim, com o carimbo do relogio do cliente — como o `accept()` faz. */
    private function aceite(Service $service, int $haSegundos, CandidateStatus $status = CandidateStatus::ACCEPTED): ServiceCandidate
    {
        if (! $service->candidates_ready_at) {
            $service->forceFill(['candidates_ready_at' => now()->subSeconds($haSegundos)])->saveQuietly();
            $service->refresh();
        }

        return ServiceCandidate::create([
            'service_id' => $service->id,
            'vendor_id' => Vendor::factory()->create()->id,
            'rank' => 1,
            'wave' => 1,
            'status' => $status,
            'quoted_amount' => 5000,
            'quoted_amount_for_vendor' => 3750,
            'quoted_distance' => 3,
            'notified_at' => now()->subSeconds($haSegundos),
            'responded_at' => now()->subSeconds($haSegundos),
        ]);
    }

    private function avanca(): void
    {
        $this->artisan('matching:advance')->assertSuccessful();
    }

    // ------------------------------------------- o prazo que o cliente ve

    public function test_o_cliente_tem_cinco_minutos_a_contar_do_primeiro_sim(): void
    {
        $service = $this->pedido(60);
        $this->aceite($service, 30);

        $deadline = app(MatchingService::class)->customerDeadline($service->refresh());

        $this->assertEqualsWithDelta(
            now()->addSeconds(self::CINCO_MINUTOS - 30)->timestamp,
            $deadline->timestamp,
            2,
            'o prazo conta do primeiro sim, e sao cinco minutos inteiros',
        );
    }

    /**
     * O caso que motivou a mudanca.
     *
     * Um pedido que esteve nove minutos a procura de quem dissesse sim ja passou
     * o antigo tecto de 180 s. Antes, o cliente recebia a notificacao "ja tens
     * propostas" de um pedido que o cron ja tinha matado — ou, no melhor caso,
     * com segundos em vez de minutos para decidir. O tempo que os profissionais
     * levaram a responder e problema deles.
     *
     * E o incentivo estava ao contrario: quanto mais depressa alguem aceitasse,
     * mais tempo o cliente tinha.
     */
    public function test_o_tempo_que_os_profissionais_levaram_nao_e_descontado_ao_cliente(): void
    {
        $service = $this->pedido(540);
        $this->aceite($service, 30);

        $deadline = app(MatchingService::class)->customerDeadline($service->refresh());

        $this->assertEqualsWithDelta(
            now()->addSeconds(self::CINCO_MINUTOS - 30)->timestamp,
            $deadline->timestamp,
            2,
            'nove minutos a espera de um sim nao tiram nada aos cinco do cliente',
        );

        $this->avanca();

        $this->assertSame(ServiceStatus::MATCHING, $service->refresh()->status);
    }

    public function test_a_quatro_minutos_e_meio_o_cliente_ainda_pode_escolher(): void
    {
        $service = $this->pedido(300);
        $this->aceite($service, 270);

        $this->avanca();

        $this->assertSame(ServiceStatus::MATCHING, $service->refresh()->status);
    }

    public function test_passados_os_cinco_minutos_o_pedido_morre(): void
    {
        $service = $this->pedido(340);
        $this->aceite($service, 301);

        $this->avanca();

        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
    }

    /** O numero que o cron usa tem de ser o numero que o ecra mostra. */
    public function test_o_pedido_morre_no_segundo_que_foi_anunciado(): void
    {
        $service = $this->pedido(340);
        $this->aceite($service, 301);

        $this->assertTrue(app(MatchingService::class)->customerDeadline($service->refresh())->isPast());

        $this->avanca();

        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
    }

    // --------------------------------------------- os mesmos minutos a pagar

    /**
     * Escolher ao ultimo segundo nao da um relogio novo.
     *
     * Antes arrancava aqui um `checkout_seconds` proprio, a contar da escolha, e
     * somava por cima: quem escolhesse ao segundo 299 ficava com 599 no total. O
     * contador no ecra nunca mostrou esse bonus — e um contador que chega a zero
     * sem nada acontecer ensina o cliente a nao acreditar nele.
     */
    public function test_os_cinco_minutos_cobrem_tambem_pagar(): void
    {
        $service = $this->pedido(340, ServiceStatus::AWAITING_PAYMENT);
        $this->aceite($service, 301, CandidateStatus::SELECTED);

        // Escolheu agora mesmo: pelo prazo de checkout ainda tinha cinco
        // minutos, mas os dele acabaram.
        $service->forceFill(['updated_at' => now()])->saveQuietly();

        $this->avanca();

        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
    }

    public function test_dentro_dos_cinco_minutos_o_pagamento_nao_e_cancelado(): void
    {
        $service = $this->pedido(240, ServiceStatus::AWAITING_PAYMENT);
        $this->aceite($service, 200, CandidateStatus::SELECTED);
        $service->forceFill(['updated_at' => now()])->saveQuietly();

        $this->avanca();

        $this->assertSame(ServiceStatus::AWAITING_PAYMENT, $service->refresh()->status);
    }

    /**
     * O `checkout_seconds` nao ficou morto: continua a ser o TECTO da fase de
     * pagamento. Aos 300 s de hoje nunca corta primeiro, mas encurta-se e corta.
     * Uma definicao que nao faz nada e pior do que nao existir.
     */
    public function test_um_checkout_mais_curto_continua_a_cortar_primeiro(): void
    {
        MatchingSettings::fake($this->definicoes(['checkout_seconds' => 60]));

        $service = $this->pedido(120, ServiceStatus::AWAITING_PAYMENT);
        $this->aceite($service, 90, CandidateStatus::SELECTED);

        // Escolheu ha 61 s. Dos cinco minutos dele faltavam mais de tres.
        $service->forceFill(['updated_at' => now()->subSeconds(61)])->saveQuietly();

        $this->avanca();

        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
    }

    // ------------------------------------------- enquanto ninguem aceitou

    /**
     * Antes do primeiro sim o pedido continua a ter um fim conhecido — nao fica
     * aberto a espera de nada.
     */
    public function test_sem_ninguem_aceite_manda_o_prazo_da_fase_de_convites(): void
    {
        $service = $this->pedido(601);
        // Com um convite ainda de pe: sem candidatos vivos o pedido morria por
        // as ondas se esgotarem, que e outra regra e mascarava esta.
        $this->conviteVivo($service);

        $this->avanca();

        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
    }

    /**
     * E esse prazo e o novo, nao o antigo. Aos 180 s da criacao um pedido que
     * ainda tem convites de pe morria com profissionais a caminho de responder.
     */
    public function test_aos_tres_minutos_sem_resposta_o_pedido_continua_vivo(): void
    {
        $service = $this->pedido(181);
        $this->conviteVivo($service);

        $this->avanca();

        $this->assertSame(ServiceStatus::MATCHING, $service->refresh()->status);
    }

    private function conviteVivo(Service $service): ServiceCandidate
    {
        return ServiceCandidate::create([
            'service_id' => $service->id,
            'vendor_id' => Vendor::factory()->create()->id,
            'rank' => 1,
            'wave' => 1,
            'status' => CandidateStatus::NOTIFIED,
            'quoted_amount' => 5000,
            'quoted_amount_for_vendor' => 3750,
            'quoted_distance' => 3,
            'notified_at' => now()->subSeconds(30),
            'expires_at' => now()->addSeconds(90),
        ]);
    }
}
