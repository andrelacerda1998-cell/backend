<?php

namespace Tests\Feature;

use App\Enums\Schedule\ScheduleRecurrence;
use App\Events\Customer\Schedule\AcceptScheduleEvent;
use App\Events\Vendor\Schedule\CreateScheduleEvent;
use App\Events\Vendor\Schedule\ServiceScheduledEvent;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Customer\ConfirmRecurringScheduleNotification;
use App\Services\Common\Services\MaterializePendingSchedule;
use App\Services\Schedule\CreateNextRecurrence;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A cadeia de um serviço que se repete, de ponta a ponta.
 *
 * As regras isoladas já tinham testes (datas, janela do lembrete, que marcação
 * um pagamento confirma). Falta o que só se vê com elas ligadas: a série avança
 * uma de cada vez, o cliente é avisado a tempo, e pagar confirma a marcação que
 * já existe em vez de criar outra — que é onde o cliente ficaria com duas
 * marcações no mesmo horário.
 */
class RecurringScheduleFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A UserFactory escolhe um género da tabela; sem seed, sai null e a
        // coluna não o aceita.
        $this->seed(GenderSeeder::class);

        // Websocket e push fora: aqui prova-se a cadeia de dados, não a
        // entrega. Sem isto o teste tenta ligar-se ao broadcaster real.
        Event::fake([
            AcceptScheduleEvent::class,
            CreateScheduleEvent::class,
            ServiceScheduledEvent::class,
        ]);
        Notification::fake();
    }

    private function makeSeries(): array
    {
        $customer = User::factory()->create();
        $vendor = Vendor::factory()->create();
        $serviceType = ServicesType::factory()->create(['time' => 90]);

        // A primeira marcação: paga no checkout e já realizada.
        $first = Schedule::query()->create([
            'vendor_id' => $vendor->id,
            'customer_id' => $customer->id,
            'service_type_id' => $serviceType->id,
            'scheduled_day' => Carbon::today('Europe/Lisbon')->subDay()->toDateString(),
            'scheduled_time_start' => '14:30:00',
            'scheduled_time_end' => '16:00:00',
            'recurrence' => ScheduleRecurrence::WEEKLY->value,
            'is_pending' => false,
        ]);

        return [$customer, $vendor, $serviceType, $first];
    }

    public function test_a_serie_avanca_uma_de_cada_vez_e_a_seguinte_nasce_por_confirmar(): void
    {
        [$customer, $vendor, $serviceType, $first] = $this->makeSeries();

        $next = app(CreateNextRecurrence::class)->forSchedule($first);

        $this->assertNotNull($next, 'A ocorrência seguinte devia ter sido criada');
        $this->assertSame(
            Carbon::parse($first->scheduled_day)->addWeek()->toDateString(),
            Carbon::parse($next->scheduled_day)->toDateString(),
        );
        $this->assertSame($first->id, $next->recurrence_parent_id);
        // Sem serviço = sem pagamento: é o que a torna "por confirmar".
        $this->assertNull($next->service_id);
        $this->assertTrue((bool) $next->is_pending);
    }

    public function test_correr_duas_vezes_no_mesmo_dia_nao_duplica_a_marcacao(): void
    {
        [, , , $first] = $this->makeSeries();

        app(CreateNextRecurrence::class)->forSchedule($first);
        $segunda = app(CreateNextRecurrence::class)->forSchedule($first);

        $this->assertNull($segunda, 'A segunda passagem não pode criar outra marcação');
        $this->assertSame(1, Schedule::query()->where('recurrence_parent_id', $first->id)->count());
    }

    public function test_o_cliente_e_avisado_uma_vez_dentro_da_janela(): void
    {
        [$customer, , , $first] = $this->makeSeries();
        $next = app(CreateNextRecurrence::class)->forSchedule($first);

        // Dois dias e meio antes: dentro da janela de 72h→48h.
        $startsAt = Carbon::parse($next->scheduled_day.' 14:30:00', 'Europe/Lisbon');
        Carbon::setTestNow($startsAt->copy()->subHours(60));

        $this->artisan('schedules:remind-recurring-payment')->assertSuccessful();
        $this->artisan('schedules:remind-recurring-payment')->assertSuccessful();

        Notification::assertSentToTimes($customer, ConfirmRecurringScheduleNotification::class, 1);
        $this->assertNotNull($next->fresh()->payment_reminder_sent_at);

        Carbon::setTestNow();
    }

    public function test_fora_da_janela_ainda_nao_se_avisa(): void
    {
        [$customer, , , $first] = $this->makeSeries();
        $next = app(CreateNextRecurrence::class)->forSchedule($first);

        // Cinco dias antes: cedo demais — um aviso a esta distância esquece-se.
        $startsAt = Carbon::parse($next->scheduled_day.' 14:30:00', 'Europe/Lisbon');
        Carbon::setTestNow($startsAt->copy()->subDays(5));

        $this->artisan('schedules:remind-recurring-payment')->assertSuccessful();

        Notification::assertNothingSentTo($customer);

        Carbon::setTestNow();
    }

    public function test_pagar_confirma_a_marcacao_existente_em_vez_de_criar_outra(): void
    {
        [$customer, $vendor, $serviceType, $first] = $this->makeSeries();
        $next = app(CreateNextRecurrence::class)->forSchedule($first);

        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => $serviceType->id,
        ]);
        $service->pending_schedule_data = [
            'scheduled' => true,
            'schedule' => [
                'schedule_id' => $next->id,
                'scheduled_day' => Carbon::parse($next->scheduled_day)->toDateString(),
                'scheduled_time_start' => '14:30',
                'scheduled_time_end' => '16:00',
            ],
        ];
        $service->save();

        app(MaterializePendingSchedule::class)->handle($service);

        // Continua a haver UMA ocorrência nesta série, agora com serviço.
        $this->assertSame(1, Schedule::query()->where('recurrence_parent_id', $first->id)->count());
        $this->assertSame($service->id, $next->fresh()->service_id);
        $this->assertNull($service->fresh()->pending_schedule_data);
    }

    public function test_nao_se_confirma_a_marcacao_de_outro_cliente(): void
    {
        [, $vendor, $serviceType, $first] = $this->makeSeries();
        $next = app(CreateNextRecurrence::class)->forSchedule($first);

        $intruso = User::factory()->create();
        $service = Service::factory()->create([
            'customer_id' => $intruso->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => $serviceType->id,
        ]);
        $service->pending_schedule_data = [
            'scheduled' => true,
            'schedule' => [
                'schedule_id' => $next->id,
                'scheduled_day' => Carbon::parse($next->scheduled_day)->toDateString(),
                'scheduled_time_start' => '14:30',
                'scheduled_time_end' => '16:00',
            ],
        ];
        $service->save();

        app(MaterializePendingSchedule::class)->handle($service);

        // A marcação da outra pessoa fica intocada; o pagamento cria a sua.
        $this->assertNull($next->fresh()->service_id);
    }
}
