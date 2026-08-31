<?php

namespace Tests\Feature\Services;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Matching\MatchingCandidateAcceptedEvent;
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
 * O que faz o tempo passar num pedido em seleção — ver docs/matching.md.
 *
 * Sem isto, um pedido que não encha à primeira fica parado para sempre e o
 * cliente espera por alguém que nunca vai ser chamado.
 */
class MatchingAdvanceTest extends TestCase
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
            'customer_choice_seconds' => 120,
            'checkout_seconds' => 300,
            'rating_bands' => [4.5, 4.0, 3.0],
            'new_vendor_min_ratings' => 5,
            'require_recent_activity_minutes' => 15,
        ]);

        Event::fake([
            MatchingInvitationEvent::class,
            MatchingCandidateAcceptedEvent::class,
            MatchingRequestClosedEvent::class,
            MatchingCandidateLostEvent::class,
        ]);
    }

    /**
     * O VendorObserver liga a auto-aceitação por omissão em todos os blocos.
     * Estes testes medem o percurso MANUAL — o profissional a responder — por
     * isso desligam-na explicitamente em vez de dependerem do que o observer
     * calhar a fazer.
     */
    private function withoutAutoAccept(Vendor $vendor): Vendor
    {
        $vendor->scheduleAvailable()->update(['auto_accept' => false]);

        return $vendor->fresh();
    }

    private function service(): Service
    {
        return Service::factory()->create([
            'status' => ServiceStatus::MATCHING,
            'vendor_id' => null,
            'amount' => null,
            'amount_for_vendor' => null,
        ]);
    }

    private function candidate(Service $service, int $rank, CandidateStatus $status, ?string $expiresAt = null): ServiceCandidate
    {
        return ServiceCandidate::create([
            'service_id' => $service->id,
            'vendor_id' => $this->withoutAutoAccept(Vendor::factory()->create())->id,
            'rank' => $rank,
            'wave' => 1,
            'status' => $status,
            'quoted_amount' => 5000,
            'quoted_amount_for_vendor' => 3750,
            'quoted_distance' => 2.40,
            'notified_at' => $status === CandidateStatus::NOTIFIED ? now() : null,
            'expires_at' => $expiresAt,
        ]);
    }

    public function test_expired_invitations_stop_looking_like_unanswered_ones(): void
    {
        $service = $this->service();
        $stale = $this->candidate($service, 1, CandidateStatus::NOTIFIED, now()->subMinute());
        $fresh = $this->candidate($service, 2, CandidateStatus::NOTIFIED, now()->addMinutes(10));

        $this->artisan('matching:advance')->assertSuccessful();

        // Sem isto não há como distinguir "não respondeu" de "ainda a pensar",
        // nem nas consultas nem nas métricas.
        $this->assertSame(CandidateStatus::EXPIRED, $stale->refresh()->status);
        $this->assertSame(CandidateStatus::NOTIFIED, $fresh->refresh()->status);
    }

    public function test_immediate_gives_up_when_nobody_answers(): void
    {
        $service = $this->service();
        $this->candidate($service, 1, CandidateStatus::NOTIFIED, now()->subSecond());

        $this->artisan('matching:advance')->assertSuccessful();

        // Regra do negócio: se ninguém puder, diz-se para tentar outra vez.
        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
    }

    public function test_declining_does_not_close_the_request_for_the_others(): void
    {
        $service = $this->service();
        $refuser = $this->candidate($service, 1, CandidateStatus::NOTIFIED, now()->addMinute());
        $other = $this->candidate($service, 2, CandidateStatus::NOTIFIED, now()->addMinute());

        app(MatchingService::class)->decline($refuser);

        // Com vários convidados em paralelo, uma recusa retira só quem recusou.
        $this->assertSame(CandidateStatus::DECLINED, $refuser->refresh()->status);
        $this->assertSame(CandidateStatus::NOTIFIED, $other->refresh()->status);
        $this->assertSame(ServiceStatus::MATCHING, $service->refresh()->status);
    }

    public function test_does_not_widen_while_the_window_is_still_open(): void
    {
        $service = $this->service();
        $called = $this->candidate($service, 1, CandidateStatus::NOTIFIED, now()->addMinute());

        $this->artisan('matching:advance')->assertSuccessful();

        // Alargar antes de a onda ter tido tempo de responder seria chamar
        // gente a mais para o mesmo trabalho.
        $this->assertSame(CandidateStatus::NOTIFIED, $called->refresh()->status);
        $this->assertSame(ServiceStatus::MATCHING, $service->refresh()->status);
    }

    public function test_an_acceptance_stops_the_machine(): void
    {
        $service = $this->service();
        $this->candidate($service, 1, CandidateStatus::ACCEPTED);
        $waiting = $this->candidate($service, 2, CandidateStatus::NOTIFIED, now()->addMinute());

        $this->artisan('matching:advance')->assertSuccessful();

        // A decisão passou a ser do cliente. Chamar mais gente agora seria
        // fazer alguém aceitar em vão.
        $this->assertSame(CandidateStatus::NOTIFIED, $waiting->refresh()->status);
        $this->assertSame(ServiceStatus::MATCHING, $service->refresh()->status);
    }

    public function test_a_customer_who_never_chooses_releases_the_professionals(): void
    {
        $service = $this->service();
        $accepted = $this->candidate($service, 1, CandidateStatus::ACCEPTED);
        // Aceitou há mais tempo do que o cliente tem para decidir.
        $accepted->update(['responded_at' => now()->subSeconds(200)]);

        $this->artisan('matching:advance')->assertSuccessful();

        // Sem este prazo, quem respondeu ficava preso a um pedido que nunca
        // resolve — com a janela fechada e sem desfecho nenhum.
        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
        $this->assertSame(CandidateStatus::LOST, $accepted->refresh()->status);
        Event::assertDispatched(MatchingRequestClosedEvent::class);
    }

    public function test_a_checkout_that_is_never_paid_does_not_hang_forever(): void
    {
        $service = $this->service();
        $chosen = $this->candidate($service, 1, CandidateStatus::SELECTED);

        $service->update(['status' => ServiceStatus::AWAITING_PAYMENT, 'vendor_id' => $chosen->vendor_id]);
        // Escolhido há mais tempo do que o prazo para pagar.
        $service->timestamps = false;
        $service->update(['updated_at' => now()->subSeconds(400)]);
        $service->timestamps = true;

        $this->artisan('matching:advance')->assertSuccessful();

        // É o pior caso para quem respondeu: não ganhou, não perdeu, e ninguém
        // lhe disse nada. O pedido fecha e ele é avisado.
        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
        $this->assertNull($service->refresh()->vendor_id);
        $this->assertSame(CandidateStatus::LOST, $chosen->refresh()->status);
        Event::assertDispatched(MatchingRequestClosedEvent::class);
    }

    public function test_scheduled_gives_up_when_nobody_answers(): void
    {
        $service = $this->service();
        // Um pedido agendado em seleção NÃO tem linha de agenda: schedule.vendor_id
        // é NOT NULL e ainda não há profissional. A intenção vive aqui até ao
        // pagamento.
        $service->pending_schedule_data = [
            'scheduled' => true,
            'schedule' => [
                'scheduled_day' => now()->addDay()->toDateString(),
                'scheduled_time_start' => now()->addDay()->setTime(10, 0)->toDateTimeString(),
            ],
        ];
        $service->save();
        $service->refresh();

        $candidate = $this->candidate($service, 1, CandidateStatus::NOTIFIED, now()->subSecond());
        $candidate->update(['notified_at' => now()->subMinutes(5)]);

        $this->artisan('matching:advance')->assertSuccessful();

        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
    }

    public function test_leaves_settled_services_alone(): void
    {
        $settled = Service::factory()->create(['status' => ServiceStatus::ACCEPTED]);

        $this->artisan('matching:advance')->assertSuccessful();

        $this->assertSame(ServiceStatus::ACCEPTED, $settled->refresh()->status);
    }
}
