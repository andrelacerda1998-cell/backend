<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Vendor\Schedule\CreateScheduleEvent;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Common\Services\MaterializePendingSchedule;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O aviso que diz ao técnico QUE serviço é que ele tem em mãos.
 *
 * O CreateScheduleEvent sai de três sítios. Dois mandavam `id` (da marcação) e
 * `service_id`; o terceiro — o que corre depois de um pagamento por MBWay ou
 * 3DS — mandava só o `id`.
 *
 * Sem o `service_id`, a app do técnico não sabe que serviço recusar: carrega em
 * Recusar, a folha fecha, e o servidor nunca é chamado. O serviço continua
 * atribuído a ele, com o cliente já cobrado, até o prazo o cancelar 20 minutos
 * depois — e com a mensagem errada, porque para o servidor ninguém respondeu.
 */
class RecusarUmAgendadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GenderSeeder::class);
        Event::fake([CreateScheduleEvent::class]);
        Notification::fake();
        Queue::fake();
    }

    public function test_o_aviso_do_agendamento_diz_sempre_qual_e_o_servico(): void
    {
        $customer = User::factory()->create();
        $vendor = Vendor::factory()->create();
        $serviceType = ServicesType::factory()->create(['time' => 60]);

        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => $serviceType->id,
            'status' => ServiceStatus::PENDING,
            'payment_status' => PaymentStatus::PAID,
        ]);

        $service->forceFill([
            'quantity' => 1,
            'pending_schedule_data' => [
                'scheduled' => true,
                'schedule' => [
                    'scheduled_day' => Carbon::today('Europe/Lisbon')->addWeek()->toDateString(),
                    'scheduled_time_start' => '10:00',
                ],
            ],
        ])->save();

        app(MaterializePendingSchedule::class)->handle($service->refresh());

        $schedule = $vendor->schedules()->where('service_id', $service->id)->firstOrFail();

        Event::assertDispatched(
            CreateScheduleEvent::class,
            function (CreateScheduleEvent $evento) use ($schedule, $service) {
                $dados = (array) $evento->scheduleDetails;

                return ($dados['id'] ?? null) === $schedule->id
                    && ($dados['service_id'] ?? null) === $service->id;
            },
        );
    }
}
