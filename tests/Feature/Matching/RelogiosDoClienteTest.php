<?php

namespace Tests\Feature\Matching;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Matching\MatchingInvitationEvent;
use App\Events\Matching\MatchingCandidateLostEvent;
use App\Events\Matching\MatchingRequestClosedEvent;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\Vendor;
use App\Services\Matching\MatchingService;
use App\Settings\MatchingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Models\PaymentOrder;
use Tests\TestCase;

/**
 * Os dois relógios do cliente (decisão do André, 06/10/2026):
 * 3 minutos para escolher a contar do ÚLTIMO técnico que aceitou, e 5 minutos
 * para pagar a contar da escolha.
 */
class RelogiosDoClienteTest extends TestCase
{
    use RefreshDatabase;

    private const ESCOLHER = 180;

    private const PAGAR = 300;

    private const TETO = 360;

    private const ESCOLHER_AGENDADO = 600;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([
            MatchingInvitationEvent::class,
            MatchingRequestClosedEvent::class,
            MatchingCandidateLostEvent::class,
        ]);
    }

    private function comDefinicoesDeTeste(): void
    {
        MatchingSettings::fake([
            'shortlist_size' => 3,
            'wave_size' => 6,
            'wave_interval_seconds' => 45,
            'max_waves' => 3,
            'vendor_response_seconds_immediate' => 120,
            'vendor_response_seconds_scheduled' => 120,
            'request_deadline_seconds' => 600,
            'customer_choice_seconds' => self::ESCOLHER,
            'customer_choice_cap_seconds' => self::TETO,
            'customer_choice_seconds_scheduled' => self::ESCOLHER_AGENDADO,
            'customer_choice_seconds_custom' => 3600,
            'custom_review_alert_weekdays' => 1,
            'custom_review_deadline_weekdays' => 2,
            'checkout_seconds' => self::PAGAR,
            'rating_bands' => [4.5, 4.0, 3.0],
            'new_vendor_min_ratings' => 5,
            'require_recent_activity_minutes' => 15,
            'max_radius_km' => 50,
        ]);
    }

    private function pedido(ServiceStatus $status = ServiceStatus::MATCHING): Service
    {
        $service = Service::factory()->create(['status' => $status]);
        $service->forceFill(['created_at' => now()->subMinutes(8)])->saveQuietly();

        return $service->refresh();
    }

    private function sim(Service $service, int $haSegundos, CandidateStatus $status = CandidateStatus::ACCEPTED): ServiceCandidate
    {
        if (! $service->candidates_ready_at) {
            $service->forceFill(['candidates_ready_at' => now()->subSeconds($haSegundos)])->saveQuietly();
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
            'notified_at' => now()->subSeconds($haSegundos + 5),
            'responded_at' => now()->subSeconds($haSegundos),
        ]);
    }

    private function avanca(): void
    {
        $this->artisan('matching:advance')->assertSuccessful();
    }

    // ------------------------------------------------------------ escolher

    public function test_tres_minutos_a_contar_do_ultimo_sim(): void
    {
        $this->comDefinicoesDeTeste();
        $service = $this->pedido();
        $this->sim($service, 170);
        $this->sim($service, 30);

        $deadline = app(MatchingService::class)->customerDeadline($service->refresh());

        $this->assertEqualsWithDelta(now()->addSeconds(self::ESCOLHER - 30)->timestamp, $deadline->timestamp, 2);
    }

    public function test_um_novo_sim_recomeca_a_contagem(): void
    {
        $this->comDefinicoesDeTeste();
        $service = $this->pedido();
        // Sozinho, este já tinha esgotado os três minutos...
        $this->sim($service, 200);
        // ...mas chegou outro há dez segundos.
        $this->sim($service, 10);

        $this->avanca();

        $this->assertSame(ServiceStatus::MATCHING, $service->refresh()->status);
    }

    public function test_tres_minutos_sem_escolher_depois_do_ultimo_sim_o_pedido_cai(): void
    {
        $this->comDefinicoesDeTeste();
        $service = $this->pedido();
        $this->sim($service, 250);
        $this->sim($service, 181);

        $this->avanca();

        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
    }

    /**
     * O teto: respostas espaçadas não esticam o prazo para sempre. Primeiro
     * "sim" há 350 s, último há 10 s: pelos três minutos teria 170 s, mas o
     * teto dos seis minutos deixa-lhe só 10.
     */
    public function test_no_agora_o_prazo_nunca_passa_seis_minutos_depois_do_primeiro_sim(): void
    {
        $this->comDefinicoesDeTeste();
        $service = $this->pedido();
        $this->sim($service, 350);
        $this->sim($service, 10);

        $deadline = app(MatchingService::class)->customerDeadline($service->refresh());

        $this->assertEqualsWithDelta(now()->addSeconds(self::TETO - 350)->timestamp, $deadline->timestamp, 2);
    }

    public function test_passado_o_teto_o_pedido_cai_mesmo_com_um_sim_recente(): void
    {
        $this->comDefinicoesDeTeste();
        $service = $this->pedido();
        $this->sim($service, 365);
        $this->sim($service, 5);

        $this->avanca();

        $this->assertSame(ServiceStatus::MATCHING_FAILED, $service->refresh()->status);
    }

    private function agendado(Service $service): Service
    {
        $amanha = now()->addDay();
        $service->forceFill(['pending_schedule_data' => [
            'scheduled' => true,
            'schedule' => ['scheduled_day' => $amanha->toDateString(), 'scheduled_time_start' => $amanha->setTime(10, 0)->toDateTimeString()],
        ]])->saveQuietly();

        return $service->refresh();
    }

    public function test_no_agendado_sao_dez_minutos_depois_do_ultimo_sim_e_sem_teto(): void
    {
        $this->comDefinicoesDeTeste();
        $service = $this->agendado($this->pedido());
        $this->sim($service, 500);
        $this->sim($service, 60);

        $deadline = app(MatchingService::class)->customerDeadline($service->refresh());

        $this->assertEqualsWithDelta(now()->addSeconds(self::ESCOLHER_AGENDADO - 60)->timestamp, $deadline->timestamp, 2);
    }

    public function test_no_agendado_aos_cinco_minutos_ainda_pode_escolher(): void
    {
        $this->comDefinicoesDeTeste();
        $service = $this->agendado($this->pedido());
        $this->sim($service, 300);

        $this->avanca();

        $this->assertSame(ServiceStatus::MATCHING, $service->refresh()->status);
    }

    // -------------------------------------------------------------- pagar

    public function test_pagar_conta_cinco_minutos_da_escolha_e_nao_do_sim(): void
    {
        $this->comDefinicoesDeTeste();
        $service = $this->pedido(ServiceStatus::AWAITING_PAYMENT);
        $escolhido = $this->sim($service, 170, CandidateStatus::SELECTED);
        DB::table('service_candidates')->where('id', $escolhido->id)->update(['updated_at' => now()->subSeconds(20)]);

        $deadline = app(MatchingService::class)->customerDeadline($service->refresh());

        $this->assertEqualsWithDelta(now()->addSeconds(self::PAGAR - 20)->timestamp, $deadline->timestamp, 2);
    }

    /**
     * MB Way à espera de confirmação no telemóvel quando os cinco minutos
     * acabam: não se corta. Cortar deixava o pedido em MatchingFailed — que
     * não liberta a cativação — e um MB Way confirmado depois ficava cativo
     * sem serviço. Quem decide é a janela do MB Way.
     */
    public function test_um_pagamento_em_curso_nao_e_cortado_pelo_relogio(): void
    {
        $this->comDefinicoesDeTeste();
        $service = $this->pedido(ServiceStatus::AWAITING_PAYMENT);
        $escolhido = $this->sim($service, 500, CandidateStatus::SELECTED);
        DB::table('service_candidates')->where('id', $escolhido->id)->update(['updated_at' => now()->subSeconds(400)]);

        $ordem = PaymentOrder::query()->create([
            'user_id' => $service->customer_id,
            'uuid' => (string) Str::uuid(),
            'amount' => 5000,
            'paid' => false,
            'status' => Status::CREATED,
            'type' => OperationType::DEFERRED,
            'refunded' => 0,
            'service' => 'piquet-testes',
            'service_uuid' => (string) Str::uuid(),
            'token' => (string) Str::uuid(),
            'ip' => '127.0.0.1',
        ]);
        $service->forceFill(['payment_order_id' => $ordem->id])->saveQuietly();

        $this->avanca();

        $this->assertSame(ServiceStatus::AWAITING_PAYMENT, $service->refresh()->status);
        $this->assertFalse(app(MatchingService::class)->expireCheckout($service));
    }

    // -------------------------------------------------- o que fica em produção

    public function test_a_migracao_deixa_os_prazos_decididos(): void
    {
        $definicoes = app(MatchingSettings::class);

        $this->assertSame(self::ESCOLHER, $definicoes->customer_choice_seconds);
        $this->assertSame(self::TETO, $definicoes->customer_choice_cap_seconds);
        $this->assertSame(self::ESCOLHER_AGENDADO, $definicoes->customer_choice_seconds_scheduled);
        $this->assertSame(self::PAGAR, $definicoes->checkout_seconds);
    }
}
