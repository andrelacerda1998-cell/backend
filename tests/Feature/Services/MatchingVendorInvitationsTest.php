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
 * O lado do profissional — ver docs/matching.md.
 *
 * O que se fixa aqui são as regras que o protegem de aceitar em vão: a janela
 * visível, o fecho ao terceiro sim, e o aviso imediato quando perde.
 */
class MatchingVendorInvitationsTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

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

        $this->service = Service::factory()->create([
            'status' => ServiceStatus::MATCHING,
            'vendor_id' => null,
            'amount' => null,
            'amount_for_vendor' => null,
        ]);
    }

    private function invitation(int $rank = 1, ?Vendor $vendor = null, ?string $expiresAt = null): ServiceCandidate
    {
        return ServiceCandidate::create([
            'service_id' => $this->service->id,
            'vendor_id' => ($vendor ?? Vendor::factory()->create())->id,
            'rank' => $rank,
            'wave' => 1,
            'status' => CandidateStatus::NOTIFIED,
            'quoted_amount' => 5000,
            'quoted_amount_for_vendor' => 3750,
            'quoted_distance' => 2.40,
            'expires_at' => $expiresAt ?? now()->addMinutes(30),
        ]);
    }

    private function asVendor(ServiceCandidate $candidate)
    {
        return $this->actingAs($candidate->vendor->user, 'api');
    }

    public function test_lists_only_invitations_whose_window_is_still_open(): void
    {
        $open = $this->invitation(1);

        // Noutro serviço: a restrição única (service_id, vendor_id) impede o
        // mesmo profissional de ser candidato duas vezes ao mesmo pedido.
        $otherService = Service::factory()->create([
            'status' => ServiceStatus::MATCHING,
            'vendor_id' => null,
            'amount' => null,
            'amount_for_vendor' => null,
        ]);

        ServiceCandidate::create([
            'service_id' => $otherService->id,
            'vendor_id' => $open->vendor_id,
            'rank' => 1,
            'wave' => 1,
            'status' => CandidateStatus::NOTIFIED,
            'quoted_amount' => 5000,
            'quoted_amount_for_vendor' => 3750,
            'quoted_distance' => 2.40,
            'expires_at' => now()->subMinute(),
        ]);

        $response = $this->asVendor($open)->getJson('/api/v1/vendor/services/matching')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($open->id, $response->json('data.0.candidate_id'));
    }

    public function test_invitation_shows_what_the_vendor_earns_and_when_it_expires(): void
    {
        $candidate = $this->invitation();

        $response = $this->asVendor($candidate)->getJson('/api/v1/vendor/services/matching')->assertOk();

        // O que ele recebe, nunca o que o cliente paga: o modelo é margem por
        // cima, e o técnico recebe 100% do que definiu.
        $this->assertSame(3750, $response->json('data.0.amount_for_vendor'));
        $this->assertArrayNotHasKey('quoted_amount', $response->json('data.0'));

        // A janela tem de ser visível: sem saber quando fica livre, o
        // profissional fica pendurado e deixa de responder.
        $this->assertNotNull($response->json('data.0.expires_at'));
    }

    public function test_accepting_does_not_take_the_service(): void
    {
        $candidate = $this->invitation();

        $this->asVendor($candidate)
            ->postJson("/api/v1/vendor/services/matching/{$candidate->id}/accept")
            ->assertOk();

        $this->assertSame(CandidateStatus::ACCEPTED, $candidate->refresh()->status);
        $this->assertNull($this->service->refresh()->vendor_id, 'aceitar é disponibilidade, não compromisso');
    }

    public function test_accepting_tells_the_customer_right_away(): void
    {
        $candidate = $this->invitation();

        $this->asVendor($candidate)
            ->postJson("/api/v1/vendor/services/matching/{$candidate->id}/accept")
            ->assertOk();

        // O cliente vê a opção aparecer no momento, em vez de esperar que a
        // janela feche. É o que torna a espera progressiva.
        Event::assertDispatched(MatchingCandidateAcceptedEvent::class);
    }

    public function test_expired_invitation_says_so_instead_of_failing_vaguely(): void
    {
        $candidate = $this->invitation(1, null, now()->subSecond());

        $this->asVendor($candidate)
            ->postJson("/api/v1/vendor/services/matching/{$candidate->id}/accept")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This invitation has expired');
    }

    public function test_late_acceptance_is_told_the_request_was_filled(): void
    {
        foreach ([1, 2, 3] as $rank) {
            app(MatchingService::class)->accept($this->invitation($rank));
        }

        $late = $this->invitation(4);

        // Nunca silêncio: saber que outro foi mais rápido é diferente de achar
        // que a app está partida.
        $this->asVendor($late)
            ->postJson("/api/v1/vendor/services/matching/{$late->id}/accept")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This request has already been filled');
    }

    public function test_the_third_acceptance_closes_the_request_for_everyone_else(): void
    {
        $waiting = $this->invitation(4);

        foreach ([1, 2, 3] as $rank) {
            app(MatchingService::class)->accept($this->invitation($rank));
        }

        Event::assertDispatched(
            MatchingRequestClosedEvent::class,
            fn ($e) => $e->payload['candidate_id'] === $waiting->id
        );
    }

    public function test_losing_is_announced_immediately(): void
    {
        $matching = app(MatchingService::class);
        $winner = $this->invitation(1);
        $loser = $this->invitation(2);

        $matching->accept($winner);
        $matching->accept($loser);
        $matching->select($winner);

        Event::assertDispatched(
            MatchingCandidateLostEvent::class,
            fn ($e) => $e->payload['candidate_id'] === $loser->id
        );
    }

    public function test_declining_is_accepted_and_frees_the_vendor(): void
    {
        $candidate = $this->invitation();

        $this->asVendor($candidate)
            ->postJson("/api/v1/vendor/services/matching/{$candidate->id}/decline")
            ->assertOk();

        $this->assertSame(CandidateStatus::DECLINED, $candidate->refresh()->status);
    }

    public function test_another_vendor_cannot_answer_someone_elses_invitation(): void
    {
        $candidate = $this->invitation();
        $intruder = Vendor::factory()->create();

        $this->actingAs($intruder->user, 'api')
            ->postJson("/api/v1/vendor/services/matching/{$candidate->id}/accept")
            ->assertStatus(404);

        $this->assertSame(CandidateStatus::NOTIFIED, $candidate->refresh()->status);
    }
}
