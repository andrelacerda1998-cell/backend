<?php

namespace Tests\Feature\Payments;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\ServiceAcceptedEvent;
use App\Events\Customer\Schedule\AcceptScheduleEvent;
use App\Events\Vendor\Schedule\ServiceScheduledEvent;
use App\Jobs\Services\MbwayPaymentCheckJob;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Common\Services\MaterializePendingSchedule;
use App\Services\Common\Services\MbwayPaymentResolver;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * O MB Way de um AGENDADO do fluxo de seleção confirma.
 *
 * Pedido 299 (15/10/2026): MB Way autorizado no Payshop, e o pedido ficou
 * em AwaitingPayment com o dinheiro cativo. Em produção, cada tentativa —
 * o poll da app de 10 em 10 s e o MbwayPaymentCheckJob — lançava
 * ServiceIsNotPending em AcceptService.php:55. O job falhou três vezes e
 * desistiu; ninguém voltou a tentar.
 *
 * Os dois caminhos materializam a agenda ANTES de gravar o pagamento, e com
 * o profissional já escolhido a materialização confirma a marcação pelo
 * `acceptSchedule`, que exige PENDING. No fluxo de seleção o serviço está em
 * AwaitingPayment. O cartão (`MatchingController::activate`) passava porque
 * põe PENDING antes; o MB Way não.
 */
class MbwayDoAgendadoDaSelecaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
        Event::fake([AcceptScheduleEvent::class, ServiceScheduledEvent::class, ServiceAcceptedEvent::class]);
    }

    /** @return array{0: Service, 1: Vendor} */
    private function agendadoPorPagar(bool $agendado = true): array
    {
        $cliente = User::factory()->create();
        $escolhido = Vendor::factory()->create();

        $service = Service::factory()->create([
            'customer_id' => $cliente->id,
            'vendor_id' => $escolhido->id,
            'services_type_id' => ServicesType::factory()->create(['time' => 60])->id,
            'status' => ServiceStatus::AWAITING_PAYMENT,
            'payment_status' => PaymentStatus::PENDING,
        ]);

        if ($agendado) {
            $service->pending_schedule_data = [
                'scheduled' => true,
                'schedule' => [
                    'scheduled_day' => now()->addDays(2)->toDateString(),
                    'scheduled_time_start' => '14:30',
                    'scheduled_time_end' => '15:30',
                ],
            ];
            $service->save();
        }

        ServiceCandidate::create([
            'service_id' => $service->id,
            'vendor_id' => $escolhido->id,
            'rank' => 1,
            'wave' => 1,
            'status' => CandidateStatus::SELECTED,
            'quoted_amount' => 2034,
            'quoted_amount_for_vendor' => 1525,
            'quoted_distance' => 2.4,
            'notified_at' => now()->subMinutes(3),
            'responded_at' => now()->subMinutes(2),
        ]);

        return [$service->refresh(), $escolhido];
    }

    /** A sequência do poll e do job: preparar, materializar, gravar o pagamento. */
    private function confirmar(Service $service): void
    {
        DB::transaction(function () use ($service) {
            $locked = Service::whereKey($service->id)->lockForUpdate()->first();

            MbwayPaymentResolver::prepararAgendadoDaSelecao($locked);
            app(MaterializePendingSchedule::class)->handle($locked);
            app(MbwayPaymentResolver::class)->markPaid($locked);
        });
    }

    public function test_o_agendado_pago_por_mb_way_fica_marcado_e_pago(): void
    {
        [$service, $escolhido] = $this->agendadoPorPagar();

        $this->confirmar($service);

        $service->refresh();
        $this->assertSame(ServiceStatus::SCHEDULED, $service->status);
        $this->assertSame(PaymentStatus::PAID, $service->payment_status);
        $this->assertNull($service->pending_schedule_data);

        $marcacao = $escolhido->schedules()->where('service_id', $service->id)->first();
        $this->assertNotNull($marcacao, 'a marcação nasce com o pagamento');
        $this->assertFalse((bool) $marcacao->is_pending, 'o técnico já tinha aceitado: entra confirmada');
    }

    /** O MbwayPaymentCheckJob, de ponta a ponta: era aqui que desistia. */
    public function test_a_verificacao_automatica_confirma_o_agendado(): void
    {
        [$service] = $this->agendadoPorPagar();

        // Sem ordem ligada, o job confirma logo — é o mesmo confirmPayment()
        // que corre quando o Payshop diz PENDING_CONFIRMATION.
        (new MbwayPaymentCheckJob($service))->handle();

        $service->refresh();
        $this->assertSame(ServiceStatus::SCHEDULED, $service->status);
        $this->assertSame(PaymentStatus::PAID, $service->payment_status);
    }

    /** O imediato continua como estava: Accepted, sem passar por PENDING. */
    public function test_o_imediato_continua_a_ir_para_aceite(): void
    {
        [$service] = $this->agendadoPorPagar(agendado: false);

        $this->confirmar($service);

        $service->refresh();
        $this->assertSame(ServiceStatus::ACCEPTED, $service->status);
        $this->assertSame(PaymentStatus::PAID, $service->payment_status);
    }

    /** Fora do fluxo de seleção (ninguém escolhido) não mexe em nada. */
    public function test_sem_profissional_escolhido_nao_mexe(): void
    {
        [$service] = $this->agendadoPorPagar();
        ServiceCandidate::where('service_id', $service->id)->update(['status' => CandidateStatus::LOST]);

        MbwayPaymentResolver::prepararAgendadoDaSelecao($service->refresh());

        $this->assertSame(ServiceStatus::AWAITING_PAYMENT, $service->refresh()->status);
    }
}
