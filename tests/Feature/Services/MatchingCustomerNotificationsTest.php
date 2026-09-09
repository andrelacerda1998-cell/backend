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
use App\Notifications\Customer\MatchingCandidatesReadyNotification;
use App\Notifications\Customer\MatchingFailedNotification;
use App\Services\Matching\MatchingService;
use App\Settings\MatchingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * O cliente também tem de ser avisado — ver docs/matching.md.
 *
 * Antes disto todas as notificações do matching iam para o profissional. Quem
 * fechasse a app durante a seleção não sabia que alguém tinha aceitado nem que
 * o pedido tinha morrido — e o relógio de escolha corria à mesma.
 */
class MatchingCustomerNotificationsTest extends TestCase
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

        Notification::fake();
    }

    private function service(bool $scheduled = false): Service
    {
        return Service::factory()->create([
            'status' => ServiceStatus::MATCHING,
            'vendor_id' => null,
            'amount' => null,
            'amount_for_vendor' => null,
            // A agenda ainda não pode existir durante a seleção (vendor_id é
            // NOT NULL); a intenção vive aqui, tal como em produção.
            'pending_schedule_data' => $scheduled ? [
                'scheduled' => true,
                'schedule' => [
                    'scheduled_day' => now()->addDays(3)->toDateString(),
                    'scheduled_time_start' => '15:00',
                ],
            ] : null,
        ]);
    }

    private function candidate(Service $service, int $rank): ServiceCandidate
    {
        $vendor = Vendor::factory()->create();
        $vendor->scheduleAvailable()->update(['auto_accept' => false]);

        return ServiceCandidate::create([
            'service_id' => $service->id,
            'vendor_id' => $vendor->id,
            'rank' => $rank,
            'wave' => 1,
            'status' => CandidateStatus::NOTIFIED,
            'quoted_amount' => 5000,
            'quoted_amount_for_vendor' => 3750,
            'quoted_distance' => 2.40,
            'notified_at' => now(),
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    public function test_o_cliente_e_avisado_quando_o_primeiro_profissional_aceita(): void
    {
        $service = $this->service();
        $candidate = $this->candidate($service, 1);

        app(MatchingService::class)->accept($candidate);

        Notification::assertSentTo(
            $service->customer,
            MatchingCandidatesReadyNotification::class
        );
    }

    public function test_as_aceitacoes_seguintes_nao_voltam_a_tocar_o_telemovel(): void
    {
        $service = $this->service();
        $matching = app(MatchingService::class);

        $matching->accept($this->candidate($service, 1));
        $matching->accept($this->candidate($service, 2));
        $matching->accept($this->candidate($service, 3));

        // Três aceitações, um aviso. Tocar-lhe o telemóvel a cada resposta pelo
        // mesmo pedido é o caminho para ele desligar as notificações.
        Notification::assertSentToTimes(
            $service->customer,
            MatchingCandidatesReadyNotification::class,
            1
        );
    }

    public function test_o_cliente_e_avisado_quando_o_pedido_morre_sem_ninguem(): void
    {
        $service = $this->service();
        $this->candidate($service, 1);

        app(MatchingService::class)->fail($service);

        Notification::assertSentTo($service->customer, MatchingFailedNotification::class);
    }

    public function test_o_imediato_desiste_quando_a_janela_de_escolha_passa(): void
    {
        $service = $this->service();
        $candidate = $this->candidate($service, 1);
        app(MatchingService::class)->accept($candidate);

        // 200 s é a janela do imediato; aos 201 já não há decisão a esperar.
        $candidate->refresh()->update(['responded_at' => now()->subSeconds(201)]);

        $this->artisan('matching:advance')->assertSuccessful();

        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
    }

    public function test_o_agendado_tem_uma_janela_de_escolha_muito_maior(): void
    {
        $service = $this->service(scheduled: true);
        $candidate = $this->candidate($service, 1);
        app(MatchingService::class)->accept($candidate);

        // O mesmo atraso que mata um pedido imediato. Aqui não pode matar: o
        // cliente marcou para outro dia e fechou a app, e os convites continuam
        // abertos durante meia hora.
        $candidate->refresh()->update(['responded_at' => now()->subSeconds(201)]);

        $this->artisan('matching:advance')->assertSuccessful();

        $this->assertSame(ServiceStatus::MATCHING, $service->refresh()->status);
    }
}
