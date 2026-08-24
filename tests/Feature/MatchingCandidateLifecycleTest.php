<?php

namespace Tests\Feature;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\Vendor;
use App\Services\Matching\MatchingService;
use App\Settings\MatchingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ciclo de vida dos candidatos — as regras que protegem o profissional de
 * aceitar em vão (ver docs/matching.md).
 */
class MatchingCandidateLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private MatchingService $matching;

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

        $this->matching = app(MatchingService::class);
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

    private function candidate(Service $service, int $rank, CandidateStatus $status, int $amount = 5000): ServiceCandidate
    {
        return ServiceCandidate::create([
            'service_id' => $service->id,
            'vendor_id' => Vendor::factory()->create()->id,
            'rank' => $rank,
            'wave' => 1,
            'status' => $status,
            'quoted_amount' => $amount,
            'quoted_amount_for_vendor' => (int) round($amount * 0.75),
            'quoted_distance' => 3.20,
            'expires_at' => $status === CandidateStatus::NOTIFIED ? now()->addMinutes(30) : null,
        ]);
    }

    public function test_accepting_does_not_assign_the_service(): void
    {
        // Aceitar é "estou disponível", não é compromisso. Só o escolhido fica
        // com o serviço — é o que permite responder a dois pedidos em paralelo.
        $service = $this->service();
        $candidate = $this->candidate($service, 1, CandidateStatus::NOTIFIED);

        $this->assertTrue($this->matching->accept($candidate));

        $service->refresh();
        $this->assertNull($service->vendor_id);
        $this->assertSame(ServiceStatus::MATCHING, $service->status);
    }

    public function test_request_closes_at_the_third_acceptance(): void
    {
        $service = $this->service();

        foreach ([1, 2, 3] as $rank) {
            $this->assertTrue($this->matching->accept($this->candidate($service, $rank, CandidateStatus::NOTIFIED)));
        }

        $fourth = $this->candidate($service, 4, CandidateStatus::NOTIFIED);

        $this->assertFalse(
            $this->matching->accept($fourth),
            'ao terceiro sim o pedido fecha — o quarto não pode aceitar uma vaga que já não existe'
        );
        $this->assertSame(CandidateStatus::LOST, $fourth->refresh()->status);
    }

    public function test_expired_window_cannot_be_accepted(): void
    {
        $service = $this->service();
        $candidate = $this->candidate($service, 1, CandidateStatus::NOTIFIED);
        $candidate->update(['expires_at' => now()->subSecond()]);

        $this->assertFalse($this->matching->accept($candidate));
        $this->assertSame(CandidateStatus::NOTIFIED, $candidate->refresh()->status);
    }

    public function test_selecting_freezes_the_quoted_price_onto_the_service(): void
    {
        // O preço mostrado é o preço cobrado: calculateHourCommission() depende
        // da hora, por isso recalcular no checkout daria outro número.
        $service = $this->service();
        $chosen = $this->candidate($service, 1, CandidateStatus::NOTIFIED, amount: 4700);
        $this->matching->accept($chosen);

        $this->assertTrue($this->matching->select($chosen));

        $service->refresh();
        $this->assertSame($chosen->vendor_id, $service->vendor_id);
        $this->assertSame(4700, $service->amount);
        $this->assertSame(3525, $service->amount_for_vendor);
        $this->assertSame(ServiceStatus::AWAITING_PAYMENT, $service->status);
        $this->assertSame(CandidateStatus::SELECTED, $chosen->refresh()->status);
    }

    public function test_losers_are_kept_not_deleted(): void
    {
        $service = $this->service();
        $winner = $this->candidate($service, 1, CandidateStatus::NOTIFIED);
        $loser = $this->candidate($service, 2, CandidateStatus::NOTIFIED);

        $this->matching->accept($winner);
        $this->matching->accept($loser);
        $this->matching->select($winner);

        // Guardados de propósito: sem isto não há como responder a "porque é
        // que não fiquei com este serviço?".
        $this->assertSame(CandidateStatus::LOST, $loser->refresh()->status);
        $this->assertDatabaseCount('service_candidates', 2);
    }

    public function test_cannot_select_someone_who_never_accepted(): void
    {
        $service = $this->service();
        $candidate = $this->candidate($service, 1, CandidateStatus::NOTIFIED);

        $this->assertFalse($this->matching->select($candidate));
        $this->assertNull($service->refresh()->vendor_id);
    }

    public function test_second_selection_is_rejected(): void
    {
        $service = $this->service();
        $first = $this->candidate($service, 1, CandidateStatus::NOTIFIED);
        $second = $this->candidate($service, 2, CandidateStatus::NOTIFIED);

        $this->matching->accept($first);
        $this->matching->accept($second);
        $this->assertTrue($this->matching->select($first));

        // O serviço já saiu de MATCHING; uma segunda escolha não pode roubá-lo.
        $this->assertFalse($this->matching->select($second));
        $this->assertSame($first->vendor_id, $service->refresh()->vendor_id);
    }

    public function test_declining_keeps_the_others_alive(): void
    {
        $service = $this->service();
        $out = $this->candidate($service, 1, CandidateStatus::NOTIFIED);
        $in = $this->candidate($service, 2, CandidateStatus::NOTIFIED);

        $this->matching->decline($out);

        $this->assertSame(CandidateStatus::DECLINED, $out->refresh()->status);
        $this->assertSame(CandidateStatus::NOTIFIED, $in->refresh()->status);
    }

    public function test_failing_closes_everyone_and_marks_the_service(): void
    {
        $service = $this->service();
        $a = $this->candidate($service, 1, CandidateStatus::NOTIFIED);
        $b = $this->candidate($service, 2, CandidateStatus::SHORTLISTED);

        $this->matching->fail($service);

        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
        $this->assertSame(CandidateStatus::LOST, $a->refresh()->status);
        $this->assertSame(CandidateStatus::LOST, $b->refresh()->status);
    }

    public function test_selectable_shows_two_when_only_two_are_available(): void
    {
        // Regra do negócio: se só dois aceitarem, aparecem os dois.
        $service = $this->service();
        $this->candidate($service, 1, CandidateStatus::ACCEPTED);
        $this->candidate($service, 2, CandidateStatus::ACCEPTED);
        $this->candidate($service, 3, CandidateStatus::DECLINED);

        $this->assertCount(2, $this->matching->selectableFor($service));
    }

    public function test_a_matching_service_does_not_block_the_vendor(): void
    {
        // Um pedido por atribuir não pode impedir o profissional de receber
        // outros — mesma razão por que Pending3DS ficou fora do "serviço aberto".
        $vendor = Vendor::factory()->create();

        Service::factory()->create([
            'vendor_id' => $vendor->id,
            'status' => ServiceStatus::MATCHING,
        ]);

        $this->assertFalse($vendor->openServices()->exists());
    }
}
