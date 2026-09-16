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
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * A hora que o cliente tem para escolher e pagar, num pedido personalizado.
 *
 * Um personalizado nao e nem imediato nem agendado. O cliente descreve o
 * problema, o backoffice define a duracao e as categorias, e so DEPOIS os
 * profissionais sao chamados. Entre pedir e haver alguem para escolher podem
 * passar horas — e o `request_deadline_seconds` conta desde a criacao.
 *
 * O resultado era o pior possivel: a notificacao "ja tens propostas" chegava ao
 * telemovel de um pedido que o cron ja tinha matado. Um prazo que comeca a
 * contar antes de haver alguma coisa para decidir nao e um prazo de decisao.
 *
 * Agora, no personalizado, a partir do primeiro aceite manda o relogio do
 * cliente — o mesmo que ele ve a contar no ecra. O que estes testes prendem e
 * isso, e a fronteira: nos outros dois modos o tecto global continua a cortar
 * por cima de tudo, que e o que o `PrazoGlobalDoPedidoTest` prova.
 */
class JanelaDoClienteTest extends TestCase
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
            'vendor_response_seconds_immediate' => 60,
            'vendor_response_seconds_scheduled' => 1800,
            'customer_choice_seconds' => 200,
            'customer_choice_seconds_scheduled' => 1800,
            'customer_choice_seconds_custom' => 3600,
            'checkout_seconds' => 300,
            'request_deadline_seconds' => 180,
            'rating_bands' => [4.5, 4.0, 3.0],
            'new_vendor_min_ratings' => 5,
            'require_recent_activity_minutes' => 15,
        ]);

        Event::fake([
            MatchingInvitationEvent::class,
            MatchingRequestClosedEvent::class,
            MatchingCandidateLostEvent::class,
        ]);
    }

    private function personalizado(string $criadoHa, ServiceStatus $status = ServiceStatus::MATCHING): Service
    {
        $service = Service::factory()->create([
            'status' => $status,
            'is_custom' => true,
            'custom_description' => 'Trocar a fechadura da porta da rua.',
            'custom_duration_minutes' => 120,
        ]);

        $service->forceFill(['created_at' => now()->sub($criadoHa)])->saveQuietly();

        return $service->refresh();
    }

    /** Aceita e carimba o inicio do relogio, como o `accept()` faz. */
    private function aceite(Service $service, string $respondeuHa): ServiceCandidate
    {
        if (! $service->candidates_ready_at) {
            $service->forceFill(['candidates_ready_at' => now()->sub($respondeuHa)])->saveQuietly();
        }

        return ServiceCandidate::create([
            'service_id' => $service->id,
            'vendor_id' => Vendor::factory()->create()->id,
            'rank' => 1,
            'wave' => 1,
            'status' => CandidateStatus::ACCEPTED,
            'quoted_amount' => 5000,
            'quoted_amount_for_vendor' => 3750,
            'quoted_distance' => 3,
            'notified_at' => now()->sub($respondeuHa),
            'responded_at' => now()->sub($respondeuHa),
        ]);
    }

    /**
     * O caso que motivou tudo: o pedido esteve duas horas no backoffice, os
     * profissionais responderam agora, e o tecto global — que conta da criacao
     * — ja tinha passado ha muito.
     */
    public function test_um_personalizado_com_propostas_sobrevive_ao_prazo_global(): void
    {
        $service = $this->personalizado('2 hours');
        $this->aceite($service, '1 minute');

        $this->artisan('matching:advance')->assertSuccessful();

        $this->assertSame(ServiceStatus::MATCHING, $service->refresh()->status);
    }

    public function test_dentro_da_hora_o_cliente_continua_a_poder_escolher(): void
    {
        $service = $this->personalizado('3 hours');
        $this->aceite($service, '59 minutes');

        $this->artisan('matching:advance')->assertSuccessful();

        $this->assertSame(ServiceStatus::MATCHING, $service->refresh()->status);
    }

    public function test_passada_a_hora_o_pedido_morre(): void
    {
        $service = $this->personalizado('3 hours');
        $this->aceite($service, '61 minutes');

        $this->artisan('matching:advance')->assertSuccessful();

        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
    }

    /**
     * A hora cobre escolher E pagar. Sem isto, escolher ao minuto 59 dava mais
     * cinco minutos por cima da hora — e o contador no ecra chegava a zero sem
     * nada acontecer, que e a maneira mais rapida de ensinar o cliente a nao
     * acreditar nele.
     */
    public function test_a_hora_conta_tambem_para_pagar(): void
    {
        $service = $this->personalizado('3 hours', ServiceStatus::AWAITING_PAYMENT);
        $candidate = $this->aceite($service, '61 minutes');
        $candidate->update(['status' => CandidateStatus::SELECTED]);

        // Escolheu agora mesmo: pelo prazo de checkout ainda tinha cinco
        // minutos, mas a hora dele ja acabou.
        $service->forceFill(['updated_at' => now()])->saveQuietly();

        $this->artisan('matching:advance')->assertSuccessful();

        $this->assertNotSame(ServiceStatus::AWAITING_PAYMENT, $service->refresh()->status);
    }

    public function test_sem_ninguem_aceite_nao_ha_relogio_do_cliente(): void
    {
        $service = $this->personalizado('1 minute');

        $this->assertNull(app(MatchingService::class)->customerDeadline($service));
    }

    /**
     * O prazo que o cliente ve tem de ser o mesmo que o cron usa. Se fossem
     * duas contas, a contagem chegava a zero com o pedido vivo — ou o pedido
     * morria com o relogio ainda a andar.
     */
    public function test_o_prazo_e_uma_hora_depois_do_primeiro_aceite(): void
    {
        $service = $this->personalizado('2 hours');
        $this->aceite($service, '10 minutes');
        // Um segundo aceite mais tarde nao adia nada: conta o PRIMEIRO.
        $this->aceite($service, '2 minutes');

        $deadline = app(MatchingService::class)->customerDeadline($service->refresh());

        $this->assertInstanceOf(CarbonInterface::class, $deadline);
        $this->assertEqualsWithDelta(
            now()->subMinutes(10)->addHour()->timestamp,
            $deadline->timestamp,
            2,
        );
    }
}
