<?php

namespace Tests\Feature\Matching;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Matching\MatchingCandidateAcceptedEvent;
use App\Events\Matching\MatchingCandidateLostEvent;
use App\Events\Matching\MatchingCandidateSelectedEvent;
use App\Events\Matching\MatchingInvitationEvent;
use App\Events\Matching\MatchingRequestClosedEvent;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\Vendor;
use App\Notifications\Vendor\MatchingOutcomeNotification;
use App\Notifications\Vendor\MatchingSelectedNotification;
use App\Services\Matching\MatchingService;
use App\Settings\MatchingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * O cliente escolheu-o e ainda não pagou: o profissional fica a saber.
 *
 * Os perdedores sabiam em segundos (`MatchingCandidateLostEvent`). O escolhido
 * ficava em silêncio até o pagamento entrar ou o prazo acabar, apesar de o
 * ecrã do "ficaste na lista" prometer "avisamos-te assim que o cliente
 * decidir". Num imediato, durante esses minutos, ainda deixava de receber
 * outros convites imediatos (`Vendor::ocupadoAgora`) sem saber porquê.
 */
class EscolhidoAEsperaDoPagamentoTest extends TestCase
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
            'customer_choice_seconds' => 180,
            'customer_choice_cap_seconds' => 360,
            'customer_choice_seconds_scheduled' => 600,
            'customer_choice_seconds_custom' => 3600,
            'custom_review_alert_weekdays' => 1,
            'custom_review_deadline_weekdays' => 2,
            'checkout_seconds' => 300,
            'rating_bands' => [4.5, 4.0, 3.0],
            'new_vendor_min_ratings' => 5,
            'require_recent_activity_minutes' => 15,
            'max_radius_km' => 50,
            'async_lead_hours' => 24,
            'vendor_response_seconds_async' => 7200,
            'wave_interval_seconds_async' => 1800,
            'request_deadline_seconds_async' => 21600,
            'customer_choice_seconds_async' => 3600,
        ]);

        Event::fake([
            MatchingInvitationEvent::class,
            MatchingCandidateAcceptedEvent::class,
            MatchingRequestClosedEvent::class,
            MatchingCandidateLostEvent::class,
            MatchingCandidateSelectedEvent::class,
        ]);
        Notification::fake();
        config(['broadcasting.default' => 'null']);

        Carbon::setTestNow(Carbon::parse('2026-10-15 10:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function pedidoComDoisSim(array $atributos = []): array
    {
        $service = Service::factory()->create(['status' => ServiceStatus::MATCHING] + $atributos);
        $service->forceFill(['candidates_ready_at' => now()->subSeconds(20)])->saveQuietly();

        $escolhido = $this->candidato($service->refresh(), 1);
        $perdedor = $this->candidato($service, 2);

        return [$service, $escolhido, $perdedor];
    }

    private function candidato(Service $service, int $rank): ServiceCandidate
    {
        return ServiceCandidate::create([
            'service_id' => $service->id,
            'vendor_id' => Vendor::factory()->create()->id,
            'rank' => $rank,
            'wave' => 1,
            'status' => CandidateStatus::ACCEPTED,
            'quoted_amount' => 5000,
            'quoted_amount_for_vendor' => 3750,
            'quoted_distance' => 3,
            'notified_at' => now()->subSeconds(30),
            'responded_at' => now()->subSeconds(20),
        ]);
    }

    public function test_o_escolhido_fica_a_saber_na_hora_e_ate_quando_o_cliente_tem_para_pagar(): void
    {
        [$service, $escolhido] = $this->pedidoComDoisSim();

        $this->assertTrue(app(MatchingService::class)->select($escolhido));

        // O prazo do aviso é o mesmo que o servidor vai aplicar: 5 minutos a
        // contar da escolha, num pedido para agora.
        $this->assertSame(
            now()->addSeconds(300)->toIso8601String(),
            app(MatchingService::class)->customerDeadline($service->refresh())?->toIso8601String(),
        );

        Event::assertDispatched(
            MatchingCandidateSelectedEvent::class,
            fn (MatchingCandidateSelectedEvent $e) => $e->userId === $escolhido->vendor->user_id
                && $e->payload['candidate_id'] === $escolhido->id
                && $e->payload['service_id'] === $service->id
                && $e->payload['pay_by'] === now()->addSeconds(300)->toIso8601String()
                && isset($e->payload['server_time']),
        );
    }

    /**
     * Agendado com antecedência: o cliente tem uma hora para escolher E pagar,
     * a contar do primeiro sim. O aviso diz esse prazo, não os 5 minutos.
     */
    public function test_no_agendado_com_antecedencia_o_prazo_e_o_do_regime_assincrono(): void
    {
        [, $escolhido] = $this->pedidoComDoisSim(['matching_async' => true]);

        $this->assertTrue(app(MatchingService::class)->select($escolhido));

        Event::assertDispatched(
            MatchingCandidateSelectedEvent::class,
            fn (MatchingCandidateSelectedEvent $e) => $e->payload['pay_by']
                === now()->subSeconds(20)->addSeconds(3600)->toIso8601String(),
        );
    }

    /**
     * Fica no histórico, para quem tinha a app fechada. E SEM push: a notícia
     * que vale tocar o telemóvel é a do pagamento, e essa já tem push.
     */
    public function test_fica_no_historico_e_nao_toca_o_telemovel(): void
    {
        [, $escolhido] = $this->pedidoComDoisSim();

        app(MatchingService::class)->select($escolhido);

        $user = $escolhido->vendor->user;

        Notification::assertSentTo(
            $user,
            MatchingSelectedNotification::class,
            function (MatchingSelectedNotification $n) use ($user, $escolhido) {
                $dados = $n->toArray($user);

                return $n->via($user) === ['database']
                    && $dados['candidate_id'] === $escolhido->id
                    && $dados['pay_by'] === now()->addSeconds(300)->toIso8601String()
                    && ! str_starts_with($dados['title'], 'notifications.')
                    && ! str_starts_with($dados['body'], 'notifications.');
            },
        );

        // Continua a não ser um desfecho: esse é para quem perde ou fica sem
        // pagamento.
        Notification::assertNotSentTo($user, MatchingOutcomeNotification::class);
    }

    public function test_quem_perde_nao_recebe_o_aviso_de_escolhido(): void
    {
        [, $escolhido, $perdedor] = $this->pedidoComDoisSim();

        app(MatchingService::class)->select($escolhido);

        Notification::assertNotSentTo($perdedor->vendor->user, MatchingSelectedNotification::class);
        Event::assertNotDispatched(
            MatchingCandidateSelectedEvent::class,
            fn (MatchingCandidateSelectedEvent $e) => $e->userId === $perdedor->vendor->user_id,
        );
        Event::assertDispatched(
            MatchingCandidateLostEvent::class,
            fn (MatchingCandidateLostEvent $e) => $e->userId === $perdedor->vendor->user_id,
        );
    }

    /** Uma escolha que não chega a acontecer não avisa ninguém de nada. */
    public function test_uma_escolha_que_falha_nao_avisa(): void
    {
        [, $escolhido] = $this->pedidoComDoisSim();
        $escolhido->update(['status' => CandidateStatus::DECLINED]);

        $this->assertFalse(app(MatchingService::class)->select($escolhido));

        Event::assertNotDispatched(MatchingCandidateSelectedEvent::class);
        Notification::assertNothingSentTo($escolhido->vendor->user);
    }

    public function test_o_aviso_tem_texto_nas_cinco_linguas(): void
    {
        foreach (['pt-pt', 'en', 'es', 'fr', 'de'] as $lingua) {
            foreach (['title', 'description'] as $campo) {
                $chave = "notifications.matchingSelected.{$campo}";

                $this->assertNotSame($chave, __($chave, [], $lingua), "{$lingua}: {$campo}");
            }
        }
    }
}
