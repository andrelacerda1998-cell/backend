<?php

namespace Tests\Feature\Services;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Matching\MatchingCandidateAcceptedEvent;
use App\Events\Matching\MatchingCandidateLostEvent;
use App\Events\Matching\MatchingFallbackEvent;
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
            MatchingFallbackEvent::class,
        ]);
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
            'vendor_id' => Vendor::factory()->create()->id,
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

    public function test_immediate_falls_back_to_the_next_professional(): void
    {
        $service = $this->service();
        $called = $this->candidate($service, 1, CandidateStatus::NOTIFIED, now()->subSecond());
        $next = $this->candidate($service, 2, CandidateStatus::SHORTLISTED);

        $this->artisan('matching:advance')->assertSuccessful();

        // "Está livre" é uma previsão, não uma promessa. Sem o fallback, uma
        // previsão errada custava ao cliente o pedido inteiro.
        $this->assertSame(CandidateStatus::EXPIRED, $called->refresh()->status);
        $this->assertSame(CandidateStatus::NOTIFIED, $next->refresh()->status);
        $this->assertSame(ServiceStatus::MATCHING, $service->refresh()->status);
    }

    public function test_the_customer_is_told_we_changed_professional(): void
    {
        $service = $this->service();
        $this->candidate($service, 1, CandidateStatus::NOTIFIED, now()->subSecond());
        $next = $this->candidate($service, 2, CandidateStatus::SHORTLISTED);

        $this->artisan('matching:advance')->assertSuccessful();

        // Sem este aviso o ecrã fica a dizer "a contactar o João" enquanto já
        // se contacta outro — e o cliente desiste a achar que ninguém responde.
        Event::assertDispatched(
            MatchingFallbackEvent::class,
            fn ($e) => $e->payload['candidate_id'] === $next->id
        );
    }

    public function test_immediate_gives_up_when_the_shortlist_runs_out(): void
    {
        $service = $this->service();
        $this->candidate($service, 1, CandidateStatus::NOTIFIED, now()->subSecond());

        $this->artisan('matching:advance')->assertSuccessful();

        // Regra do negócio: se ninguém puder, diz-se para tentar outra vez.
        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
    }

    public function test_declining_immediately_calls_the_next_one(): void
    {
        $service = $this->service();
        $refuser = $this->candidate($service, 1, CandidateStatus::NOTIFIED, now()->addMinute());
        $next = $this->candidate($service, 2, CandidateStatus::SHORTLISTED);

        app(MatchingService::class)->decline($refuser);

        // Não se espera pela janela: ele já disse que não.
        $this->assertSame(CandidateStatus::NOTIFIED, $next->refresh()->status);
    }

    public function test_does_not_advance_while_the_window_is_still_open(): void
    {
        $service = $this->service();
        $called = $this->candidate($service, 1, CandidateStatus::NOTIFIED, now()->addMinute());
        $next = $this->candidate($service, 2, CandidateStatus::SHORTLISTED);

        $this->artisan('matching:advance')->assertSuccessful();

        $this->assertSame(CandidateStatus::NOTIFIED, $called->refresh()->status);
        $this->assertSame(CandidateStatus::SHORTLISTED, $next->refresh()->status, 'ninguém é chamado a mais');
    }

    public function test_an_acceptance_stops_the_machine(): void
    {
        $service = $this->service();
        $accepted = $this->candidate($service, 1, CandidateStatus::ACCEPTED);
        $waiting = $this->candidate($service, 2, CandidateStatus::SHORTLISTED);

        $this->artisan('matching:advance')->assertSuccessful();

        // A decisão passou a ser do cliente. Chamar mais gente agora seria
        // fazer alguém aceitar em vão.
        $this->assertSame(CandidateStatus::SHORTLISTED, $waiting->refresh()->status);
        $this->assertSame(ServiceStatus::MATCHING, $service->refresh()->status);
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
