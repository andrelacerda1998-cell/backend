<?php

namespace Tests\Feature\Payments;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Customer\Mbway\PaymentExpiredNotification;
use App\Notifications\Customer\Mbway\PaymentRefusedNotification;
use App\Notifications\Customer\Mbway\PaymentSuccessNotification;
use App\Notifications\Vendor\MatchingOutcomeNotification;
use App\Notifications\Vendor\ServiceWonNotification;
use App\Services\Common\Services\ConsultarOrdemPayshop;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Models\PaymentOrder;
use Tests\TestCase;

/**
 * A rede de segurança dos MB Way presos (`services:resgatar-mbway-presos`).
 *
 * O pedido 299 (15/10/2026) ficou em AwaitingPayment com o MB Way autorizado:
 * a confirmação rebentou três vezes, o job desistiu, e nada voltou a olhar
 * para ele. Aqui, um MB Way por resolver há mais de 10 minutos leva a última
 * verificação: autorizado confirma, recusado recusa, nunca respondido expira.
 */
class RedeDeSegurancaMbwayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
        Queue::fake();
        config(['broadcasting.default' => 'null']);

        // Nunca bater no Paylands a sério: o que o ServiceObserver pergunta
        // ao libertar o cativo vai para uma porta sem ninguém (e é reportado).
        config([
            'payshop-sdk.environment' => 'sandbox',
            'payshop-sdk.api_endpoint.sandbox' => 'http://127.0.0.1:9/',
            'payshop-sdk.connect_timeout' => 0.25,
            'payshop-sdk.timeout' => 0.25,
        ]);
    }

    /** O que o Payshop responde quando se lhe pergunta pela ordem. null = está em baixo. */
    private function payshopResponde(?Status $estado): void
    {
        $this->app->instance(ConsultarOrdemPayshop::class, new class($estado) extends ConsultarOrdemPayshop
        {
            public function __construct(private readonly ?Status $estado) {}

            public function atualizar(PaymentOrder $ordem): PaymentOrder
            {
                if ($this->estado === null) {
                    throw new \RuntimeException('Payshop em baixo');
                }

                $ordem->status = $this->estado;
                $ordem->save();

                return $ordem;
            }
        });
    }

    /**
     * Um pedido de seleção à espera do MB Way, com o técnico escolhido e a
     * ordem criada há `$minutos` minutos (por omissão, já fora da janela do job).
     *
     * @return array{0: Service, 1: Vendor, 2: User}
     */
    private function mbWayPorResolver(int $minutos = 12, bool $agendado = false): array
    {
        $cliente = User::factory()->create();
        $escolhido = Vendor::factory()->create();

        $service = Service::factory()->create([
            'customer_id' => $cliente->id,
            'vendor_id' => $escolhido->id,
            'services_type_id' => ServicesType::factory()->create(['time' => 60])->id,
            'status' => ServiceStatus::AWAITING_PAYMENT,
            'payment_status' => PaymentStatus::PENDING,
            'amount' => 2034,
            'amount_for_vendor' => 1525,
        ]);

        if ($agendado) {
            $service->pending_schedule_data = ['scheduled' => true, 'schedule' => [
                'scheduled_day' => now()->addDays(2)->toDateString(),
                'scheduled_time_start' => '14:30',
                'scheduled_time_end' => '15:30',
            ]];
            $service->save();
        }

        ServiceCandidate::create([
            'service_id' => $service->id, 'vendor_id' => $escolhido->id, 'rank' => 1, 'wave' => 1,
            'status' => CandidateStatus::SELECTED, 'quoted_amount' => 2034, 'quoted_amount_for_vendor' => 1525,
            'quoted_distance' => 2.4, 'notified_at' => now()->subMinutes($minutos + 2),
            'responded_at' => now()->subMinutes($minutos + 1),
        ]);

        $ordem = PaymentOrder::query()->create([
            'user_id' => $cliente->id,
            'uuid' => (string) Str::uuid(),
            'amount' => 2034,
            'paid' => false,
            'status' => Status::CREATED,
            'type' => OperationType::DEFERRED,
            'refunded' => 0,
            'service' => 'fake',
            'service_uuid' => (string) Str::uuid(),
            'token' => 'fake-token',
        ]);
        PaymentOrder::whereKey($ordem->id)->update(['created_at' => now()->subMinutes($minutos)]);
        $service->forceFill(['payment_order_id' => $ordem->id])->save();

        return [$service->refresh(), $escolhido, $cliente];
    }

    private function correr(): void
    {
        $this->artisan('services:resgatar-mbway-presos')->assertSuccessful();
    }

    public function test_autorizado_e_preso_confirma_e_o_tecnico_fica_a_saber_que_ganhou(): void
    {
        [$service, $escolhido, $cliente] = $this->mbWayPorResolver();
        $this->payshopResponde(Status::PENDING_CONFIRMATION);

        $this->correr();

        $service->refresh();
        $this->assertSame(PaymentStatus::PAID, $service->payment_status);
        $this->assertSame(ServiceStatus::ACCEPTED, $service->status);
        Notification::assertSentTo($escolhido->user, ServiceWonNotification::class);
        Notification::assertSentTo($cliente, PaymentSuccessNotification::class);
    }

    /** O caso do 299: agendado, com o técnico escolhido. */
    public function test_um_agendado_autorizado_fica_marcado_e_pago(): void
    {
        [$service, $escolhido] = $this->mbWayPorResolver(agendado: true);
        $this->payshopResponde(Status::PENDING_CONFIRMATION);

        $this->correr();

        $service->refresh();
        $this->assertSame(ServiceStatus::SCHEDULED, $service->status);
        $this->assertSame(PaymentStatus::PAID, $service->payment_status);
        $this->assertNotNull($escolhido->schedules()->where('service_id', $service->id)->first());
    }

    public function test_recusado_no_banco_recusa_e_o_tecnico_escolhido_sabe_com_push(): void
    {
        [$service, $escolhido, $cliente] = $this->mbWayPorResolver();
        $this->payshopResponde(Status::REFUNDED);

        $this->correr();

        $this->assertSame(ServiceStatus::REFUSED_MBWAY, $service->refresh()->status);
        $this->assertSame(CandidateStatus::LOST, $service->candidates()->first()->status);
        Notification::assertSentTo($cliente, PaymentRefusedNotification::class);
        Notification::assertSentTo(
            $escolhido->user,
            MatchingOutcomeNotification::class,
            fn (MatchingOutcomeNotification $n) => $n->toArray($escolhido->user)['outcome'] === 'closed'
                && in_array('expo', $n->via($escolhido->user), true),
        );
    }

    public function test_nunca_respondido_expira_e_o_tecnico_escolhido_sabe(): void
    {
        [$service, $escolhido, $cliente] = $this->mbWayPorResolver();
        $this->payshopResponde(Status::CREATED);

        $this->correr();

        $this->assertSame(ServiceStatus::EXPIRED_MBWAY, $service->refresh()->status);
        $this->assertSame(CandidateStatus::LOST, $service->candidates()->first()->status);
        Notification::assertSentTo($cliente, PaymentExpiredNotification::class);
        Notification::assertSentTo($escolhido->user, MatchingOutcomeNotification::class);
    }

    /** Dentro da janela do job, o job é que decide. */
    public function test_um_mb_way_recente_fica_para_o_job(): void
    {
        [$service] = $this->mbWayPorResolver(minutos: 5);
        $this->payshopResponde(Status::PENDING_CONFIRMATION);

        $this->correr();

        $this->assertSame(ServiceStatus::AWAITING_PAYMENT, $service->refresh()->status);
        $this->assertSame(PaymentStatus::PENDING, $service->payment_status);
    }

    /** Mais de 2 horas: decide a equipa. Confirmar uma coisa de há dias avisava toda a gente fora de horas. */
    public function test_um_mb_way_antigo_fica_para_a_equipa(): void
    {
        [$service] = $this->mbWayPorResolver(minutos: 180);
        $this->payshopResponde(Status::PENDING_CONFIRMATION);

        $this->correr();

        $this->assertSame(ServiceStatus::AWAITING_PAYMENT, $service->refresh()->status);
    }

    /** Payshop em baixo: o comando não cai, nada muda, e tenta outra vez na próxima volta. */
    public function test_com_o_payshop_em_baixo_nada_muda_e_o_comando_continua(): void
    {
        [$service] = $this->mbWayPorResolver();
        $this->payshopResponde(null);

        $this->correr();

        $this->assertSame(ServiceStatus::AWAITING_PAYMENT, $service->refresh()->status);
        $this->assertSame(PaymentStatus::PENDING, $service->payment_status);
    }

    public function test_um_pagamento_ja_confirmado_nao_e_mexido(): void
    {
        [$service, $escolhido] = $this->mbWayPorResolver();
        $service->forceFill(['status' => ServiceStatus::ACCEPTED, 'payment_status' => PaymentStatus::PAID])->save();
        $this->payshopResponde(Status::PENDING_CONFIRMATION);

        $this->correr();

        Notification::assertNotSentTo($escolhido->user, ServiceWonNotification::class);
    }
}
