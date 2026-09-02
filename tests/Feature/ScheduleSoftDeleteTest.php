<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\ServicesType;
use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Observers\ScheduleObserver;
use App\Jobs\Services\ScheduleReminderJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Cancelar um agendamento não pode destruir a prova de como ele existiu.
 *
 * Antes desta correção o CancelScheduleController fazia $schedule->delete() e o
 * modelo não usava SoftDeletes — a linha desaparecia da BD, e num incidente
 * (técnico não apareceu) deixava de ser possível saber se o agendamento tinha
 * sido auto-confirmado ou ficado pendente. Com SoftDeletes a linha fica retida
 * (deleted_at) mas some das consultas normais.
 */
class ScheduleSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function makeSchedule(array $overrides = []): Schedule
    {
        $vendor = Vendor::factory()->create();
        $customer = User::factory()->create();
        $type = ServicesType::factory()->create();
        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => $type->id,
        ]);

        return Schedule::create(array_merge([
            'vendor_id' => $vendor->id,
            'customer_id' => $customer->id,
            'service_type_id' => $type->id,
            'service_id' => $service->id,
            'scheduled_day' => now()->addDay()->toDateString(),
            'scheduled_time_start' => '11:30:00',
            'scheduled_time_end' => '12:30:00',
            'is_pending' => true,
        ], $overrides));
    }

    public function test_delete_e_soft_e_a_linha_fica_retida_mas_escondida(): void
    {
        $schedule = $this->makeSchedule();
        $id = $schedule->id;

        $schedule->delete();

        // some das consultas normais...
        $this->assertNull(Schedule::find($id), 'agendamento cancelado não devia aparecer nas queries normais');
        // ...mas continua na BD, recuperável
        $this->assertTrue($schedule->trashed());
        $this->assertNotNull(DB::table('schedule')->where('id', $id)->value('deleted_at'), 'deleted_at devia estar preenchido na BD');
        $this->assertNotNull(Schedule::withTrashed()->find($id), 'a linha devia continuar recuperável via withTrashed()');
    }

    public function test_pode_reagendar_o_mesmo_slot_depois_de_cancelar(): void
    {
        $schedule = $this->makeSchedule();
        $vendorId = $schedule->vendor_id;
        $customerId = $schedule->customer_id;
        $typeId = $schedule->service_type_id;
        $day = $schedule->scheduled_day;

        $schedule->delete();

        // a verificação de duplicado do ScheduleController usa ->exists(), que
        // aplica o global scope do SoftDeletes: a linha cancelada não deve contar.
        $bloqueia = Schedule::query()
            ->where('vendor_id', $vendorId)
            ->where('customer_id', $customerId)
            ->where('scheduled_day', $day)
            ->where('service_type_id', $typeId)
            ->exists();

        $this->assertFalse($bloqueia, 'um agendamento cancelado não devia impedir reagendar o mesmo slot');
    }

    public function test_soft_delete_nao_dispara_lembrete(): void
    {
        Queue::fake();
        $schedule = $this->makeSchedule();
        // limpar qualquer dispatch do setup: só interessa o que o delete faz
        Queue::fake();

        $schedule->delete();

        // o ScheduleObserver reage a created/updated, não a deleted — o soft-delete
        // (que emite deleting/deleted, não updated) não pode agendar um lembrete.
        Queue::assertNotPushed(ScheduleReminderJob::class);
    }
}
