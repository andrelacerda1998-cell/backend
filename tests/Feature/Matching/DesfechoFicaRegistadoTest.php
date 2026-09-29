<?php

namespace Tests\Feature\Matching;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Matching\MatchingCandidateAcceptedEvent;
use App\Events\Matching\MatchingCandidateLostEvent;
use App\Events\Matching\MatchingInvitationEvent;
use App\Events\Matching\MatchingRequestClosedEvent;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\Vendor;
use App\Notifications\Vendor\MatchingInvitationNotification;
use App\Notifications\Vendor\MatchingOutcomeNotification;
use App\Services\Matching\MatchingService;
use App\Settings\MatchingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * O profissional que aceitou fica a saber o que aconteceu ao pedido.
 *
 * Os dois desfechos possiveis depois do sim dele — o cliente escolheu outro, ou
 * escolheu-o e nao pagou — ja viajavam por websocket. Mas o `notifyVendor` saia
 * antes de escrever notificacao nenhuma, e a app do tecnico limitava-se a tirar
 * o cartao da lista. Resultado: com a app fechada no momento do evento, nada; e
 * ao abri-la, um cartao que desapareceu sem explicacao.
 *
 * O proprio comentario que justificava a ausencia de push dizia que estas
 * coisas "chegam quando ele abrir a app". Nada as levava la. Este teste e o que
 * garante que agora vao.
 *
 * SEM PUSH continua a ser a regra: o que se prende aqui e o registo, e tambem
 * que o push NAO sai.
 */
class DesfechoFicaRegistadoTest extends TestCase
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
            MatchingCandidateAcceptedEvent::class,
            MatchingRequestClosedEvent::class,
            MatchingCandidateLostEvent::class,
        ]);
        Notification::fake();
        config(['broadcasting.default' => 'null']);
    }

    private function pedidoComDoisSim(): array
    {
        $service = Service::factory()->create(['status' => ServiceStatus::MATCHING]);
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

    public function test_quem_perde_fica_com_o_motivo_no_historico(): void
    {
        [, $escolhido, $perdedor] = $this->pedidoComDoisSim();

        app(MatchingService::class)->select($escolhido);

        Notification::assertSentTo(
            $perdedor->vendor->user,
            MatchingOutcomeNotification::class,
            function (MatchingOutcomeNotification $n) use ($perdedor) {
                $dados = $n->toArray($perdedor->vendor->user);

                return $dados['outcome'] === 'lost'
                    && $dados['candidate_id'] === $perdedor->id
                    && $dados['title'] !== ''
                    && $dados['body'] !== '';
            },
        );
    }

    /** Quem ganhou nao recebe um aviso de desfecho — ganhou. */
    public function test_quem_e_escolhido_nao_recebe_aviso_de_desfecho(): void
    {
        [, $escolhido] = $this->pedidoComDoisSim();

        app(MatchingService::class)->select($escolhido);

        Notification::assertNotSentTo($escolhido->vendor->user, MatchingOutcomeNotification::class);
    }

    /**
     * O outro desfecho: foi escolhido, reservou o tempo, e o cliente nao pagou.
     * Este e o que mais precisa de explicacao — o tempo dele esteve bloqueado.
     */
    public function test_quem_foi_escolhido_e_nao_recebeu_pagamento_e_avisado(): void
    {
        $service = Service::factory()->create(['status' => ServiceStatus::AWAITING_PAYMENT]);
        $service->forceFill(['candidates_ready_at' => now()->subSeconds(400)])->saveQuietly();

        $candidato = $this->candidato($service->refresh(), 1);
        $candidato->update(['status' => CandidateStatus::SELECTED]);

        $this->assertTrue(app(MatchingService::class)->expireCheckout($service->refresh()));

        Notification::assertSentTo(
            $candidato->vendor->user,
            MatchingOutcomeNotification::class,
            function (MatchingOutcomeNotification $n) use ($candidato) {
                return $n->toArray($candidato->vendor->user)['outcome'] === 'closed';
            },
        );
    }

    /**
     * A regra que NAO muda: o desfecho nao toca o telemovel.
     *
     * Vai so por `database`. O push e para os convites; castigar quem aceitou
     * com um aviso de que perdeu e o caminho para ele deixar de aceitar.
     */
    public function test_o_desfecho_nao_leva_push(): void
    {
        [, $escolhido, $perdedor] = $this->pedidoComDoisSim();

        app(MatchingService::class)->select($escolhido);

        Notification::assertSentTo(
            $perdedor->vendor->user,
            MatchingOutcomeNotification::class,
            function (MatchingOutcomeNotification $n) use ($perdedor) {
                return $n->via($perdedor->vendor->user) === ['database'];
            },
        );

        Notification::assertNotSentTo($perdedor->vendor->user, MatchingInvitationNotification::class);
    }

    /**
     * A preferencia "novos pedidos" nao silencia o desfecho.
     *
     * Quem a desliga esta a dizer que nao quer ser chamado para trabalho novo,
     * nao que nao quer saber o que aconteceu ao que aceitou. E sem push nao ha
     * incomodo nenhum para poupar.
     */
    public function test_desligar_os_avisos_de_pedidos_novos_nao_esconde_o_desfecho(): void
    {
        [, $escolhido, $perdedor] = $this->pedidoComDoisSim();

        $perdedor->vendor->update(['notification_preferences' => ['new_requests' => false]]);
        $this->assertFalse($perdedor->vendor->fresh()->shouldReceive('new_requests'));

        app(MatchingService::class)->select($escolhido);

        Notification::assertSentTo($perdedor->vendor->user, MatchingOutcomeNotification::class);
    }
}
