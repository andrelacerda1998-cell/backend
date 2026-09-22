<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\ServiceTimeoutEvent as CommonServiceTimeoutEvent;
use App\Events\Customer\Schedule\AcceptScheduleEvent;
use App\Events\Vendor\Schedule\ServiceScheduledEvent;
use App\Events\Vendor\Services\ServiceTimeoutEvent as VendorServiceTimeoutEvent;
use App\Jobs\Services\CancelJobWithoutReactionJob;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Um agendamento só fica do técnico quando ele o aceita.
 *
 * A lista de pendentes tinha um prazo escondido: abrir o ecrã gravava
 * `is_pending = false` em tudo o que estivesse pago há mais de 20 minutos, sem
 * o técnico tocar em nada.
 *
 * O dano principal não era mostrar uma aceitação que não houve. Era que o
 * CancelJobWithoutReactionJob, que trata a falta de resposta, tem a guarda
 * `! $schedule->is_pending` e desistia — deixando o serviço PENDING para
 * sempre: nunca aceite, nunca cancelado, nunca reembolsado. Os dois corriam ao
 * mesmo prazo (1200s), por isso era uma corrida entre abrir a app e a fila.
 */
class AgendamentoPendenteNaoSeAceitaSozinhoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A UserFactory escolhe um género da tabela; sem seed, sai null.
        $this->seed(GenderSeeder::class);

        // Websocket e push fora: aqui prova-se a transição de estado, não a entrega.
        Event::fake([
            AcceptScheduleEvent::class,
            ServiceScheduledEvent::class,
            CommonServiceTimeoutEvent::class,
            VendorServiceTimeoutEvent::class,
        ]);
        Notification::fake();
    }

    /**
     * @return array{0: Vendor, 1: Service, 2: Schedule}
     */
    private function pedidoPagoSemResposta(
        int $minutosDesdeOPedido = 21,
        ServiceStatus $estado = ServiceStatus::PENDING,
    ): array {
        $customer = User::factory()->create();
        $vendor = Vendor::factory()->create();
        $serviceType = ServicesType::factory()->create(['time' => 90]);

        // PENDING é o estado de quem espera resposta do técnico — e o único em
        // que o CancelJobWithoutReactionJob pode agir.
        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => $serviceType->id,
            'status' => $estado,
            'payment_status' => PaymentStatus::PAID,
        ]);

        $schedule = Schedule::query()->create([
            'vendor_id' => $vendor->id,
            'customer_id' => $customer->id,
            'service_type_id' => $serviceType->id,
            'service_id' => $service->id,
            'scheduled_day' => Carbon::today('Europe/Lisbon')->addWeek()->toDateString(),
            'scheduled_time_start' => '14:30:00',
            'scheduled_time_end' => '16:00:00',
            'is_pending' => true,
        ]);

        $schedule->forceFill([
            'created_at' => Carbon::now()->subMinutes($minutosDesdeOPedido),
        ])->save();

        return [$vendor, $service, $schedule];
    }

    private function janela(Schedule $schedule): array
    {
        return [
            Carbon::parse($schedule->scheduled_day.' '.$schedule->scheduled_time_start),
            Carbon::parse($schedule->scheduled_day.' '.$schedule->scheduled_time_end),
        ];
    }

    public function test_abrir_a_lista_de_pendentes_nao_aceita_o_pedido_pelo_tecnico(): void
    {
        [$vendor, , $schedule] = $this->pedidoPagoSemResposta();

        $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/vendor/schedule/pending-schedules')
            ->assertSuccessful();

        $this->assertTrue(
            (bool) $schedule->fresh()->is_pending,
            'Abrir a lista não pode confirmar um pedido que o técnico nunca respondeu',
        );
    }

    public function test_abrir_a_lista_nao_desliga_o_cancelamento_por_falta_de_resposta(): void
    {
        [$vendor, $service, $schedule] = $this->pedidoPagoSemResposta();

        $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/vendor/schedule/pending-schedules')
            ->assertSuccessful();

        // Antes desta correção o job encontrava o agendamento já "aceite" e
        // desistia: o serviço ficava PENDING para sempre, fora da vista dos dois.
        (new CancelJobWithoutReactionJob($service->fresh()))->handle();

        $this->assertSame(ServiceStatus::CANCELED, $service->fresh()->status);

        // O aviso faz parte do cancelamento: sem ele ninguém sabe que caiu.
        Event::assertDispatched(CommonServiceTimeoutEvent::class);
        Event::assertDispatched(VendorServiceTimeoutEvent::class);

        $this->assertSoftDeleted('schedule', ['id' => $schedule->id]);
    }

    public function test_o_cancelamento_por_falta_de_resposta_liberta_a_hora_do_tecnico(): void
    {
        [$vendor, $service, $schedule] = $this->pedidoPagoSemResposta();
        [$inicio, $fim] = $this->janela($schedule);

        $this->assertFalse($vendor->hasFreeSlot($inicio, $fim), 'com o pedido de pé, a hora está ocupada');

        (new CancelJobWithoutReactionJob($service->fresh()))->handle();

        // Todos os outros cancelamentos apagam a marcação. Este era o único que
        // a deixava viva — e o hasFreeSlot conta as marcações do dia sem olhar
        // ao estado do serviço, por isso a hora ficava bloqueada para sempre por
        // um pedido que ninguém aceitou, sem aparecer em ecrã nenhum.
        $this->assertTrue(
            $vendor->fresh()->hasFreeSlot($inicio, $fim),
            'Cancelado o pedido, a hora tem de voltar a estar livre',
        );
    }

    public function test_aceitar_continua_a_ser_um_gesto_do_tecnico(): void
    {
        [$vendor, $service, $schedule] = $this->pedidoPagoSemResposta();

        $this->actingAs($vendor->user, 'api')
            ->postJson('/api/v1/vendor/schedule/accept', ['schedule_id' => $schedule->id])
            ->assertSuccessful();

        $this->assertFalse((bool) $schedule->fresh()->is_pending);

        // Aceitar é mais do que baixar a flag: é o serviço passar a SCHEDULED e
        // os dois lados serem avisados.
        $this->assertSame(ServiceStatus::SCHEDULED, $service->fresh()->status);
        Event::assertDispatched(AcceptScheduleEvent::class);
        Event::assertDispatched(ServiceScheduledEvent::class);
    }

    public function test_depois_de_aceite_o_prazo_ja_nao_cancela(): void
    {
        [$vendor, $service, $schedule] = $this->pedidoPagoSemResposta();

        $this->actingAs($vendor->user, 'api')
            ->postJson('/api/v1/vendor/schedule/accept', ['schedule_id' => $schedule->id])
            ->assertSuccessful();

        // A outra metade do par: a guarda do job existe para não cancelar o que
        // o técnico aceitou entre o dispatch e o fim do prazo.
        (new CancelJobWithoutReactionJob($service->fresh()))->handle();

        $this->assertSame(ServiceStatus::SCHEDULED, $service->fresh()->status);
        $this->assertNotSoftDeleted('schedule', ['id' => $schedule->id]);
    }

    public function test_a_lista_mostra_o_agendamento_e_esconde_o_que_ja_caiu(): void
    {
        // O filtro por estado do serviço é a única linha que sobreviveu à
        // correção. Sem este teste, esvaziar o método por completo passava.
        [$vendor, , $aparece] = $this->pedidoPagoSemResposta(estado: ServiceStatus::SCHEDULED);
        [, , $escondido] = $this->pedidoPagoSemResposta(estado: ServiceStatus::CANCELED);

        $resposta = $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/vendor/schedule/pending-schedules')
            ->assertSuccessful()
            ->json('data');

        $ids = array_column($resposta, 'id');

        $this->assertContains($aparece->id, $ids);
        $this->assertNotContains($escondido->id, $ids);
    }
}
