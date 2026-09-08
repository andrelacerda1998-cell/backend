<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\ServicesType;
use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Vendor\ScheduleAttendanceReminderNotification;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Lembrete de presenca ao tecnico, 72h antes, e o botao de confirmar.
 *
 * Um agendamento aceite ha semanas esquece-se, e quem fica a espera em casa e o
 * cliente. Estas regras decidem quem e avisado e quando — e quem pode confirmar
 * o que.
 */
class VendorAttendanceReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    private function makeSchedule(int $daysAhead = 3, bool $paid = true): array
    {
        $customer = User::factory()->create();
        $vendor = Vendor::factory()->create();
        $serviceType = ServicesType::factory()->create(['time' => 90]);

        $service = $paid ? Service::factory()->create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => $serviceType->id,
        ]) : null;

        $schedule = Schedule::query()->create([
            'vendor_id' => $vendor->id,
            'customer_id' => $customer->id,
            'service_type_id' => $serviceType->id,
            'service_id' => $service?->id,
            'scheduled_day' => Carbon::today('Europe/Lisbon')->addDays($daysAhead)->toDateString(),
            'scheduled_time_start' => '14:30:00',
            'scheduled_time_end' => '16:00:00',
            'is_pending' => false,
        ]);

        return [$vendor, $schedule];
    }

    public function test_o_tecnico_e_avisado_uma_so_vez_a_72h(): void
    {
        [$vendor, $schedule] = $this->makeSchedule();

        $startsAt = Carbon::parse($schedule->scheduled_day.' 14:30:00', 'Europe/Lisbon');
        Carbon::setTestNow($startsAt->copy()->subHours(70));

        $this->artisan('schedules:remind-vendor-attendance')->assertSuccessful();
        $this->artisan('schedules:remind-vendor-attendance')->assertSuccessful();

        Notification::assertSentToTimes($vendor->user, ScheduleAttendanceReminderNotification::class, 1);
        $this->assertNotNull($schedule->fresh()->vendor_reminder_sent_at);

        Carbon::setTestNow();
    }

    public function test_a_uma_semana_ainda_nao_se_avisa(): void
    {
        [$vendor, $schedule] = $this->makeSchedule(7);

        $startsAt = Carbon::parse($schedule->scheduled_day.' 14:30:00', 'Europe/Lisbon');
        Carbon::setTestNow($startsAt->copy()->subDays(7));

        $this->artisan('schedules:remind-vendor-attendance')->assertSuccessful();

        Notification::assertNotSentTo($vendor->user, ScheduleAttendanceReminderNotification::class);

        Carbon::setTestNow();
    }

    public function test_ocorrencia_por_pagar_nao_gera_lembrete_ao_tecnico(): void
    {
        [$vendor, $schedule] = $this->makeSchedule(3, false);

        $startsAt = Carbon::parse($schedule->scheduled_day.' 14:30:00', 'Europe/Lisbon');
        Carbon::setTestNow($startsAt->copy()->subHours(70));

        $this->artisan('schedules:remind-vendor-attendance')->assertSuccessful();

        // Ainda não é trabalho dele: avisá-lo seria prometer-lhe um serviço que
        // pode nunca acontecer.
        Notification::assertNotSentTo($vendor->user, ScheduleAttendanceReminderNotification::class);

        Carbon::setTestNow();
    }

    public function test_o_botao_confirma_a_presenca_e_repetir_nao_e_erro(): void
    {
        [$vendor, $schedule] = $this->makeSchedule();

        $this->actingAs($vendor->user, 'api')
            ->postJson("/api/v1/vendor/schedule/{$schedule->id}/confirm-attendance")
            ->assertSuccessful();

        $confirmedAt = $schedule->fresh()->vendor_confirmed_at;
        $this->assertNotNull($confirmedAt);

        $this->actingAs($vendor->user, 'api')
            ->postJson("/api/v1/vendor/schedule/{$schedule->id}/confirm-attendance")
            ->assertSuccessful();

        $this->assertEquals($confirmedAt, $schedule->fresh()->vendor_confirmed_at);
    }

    public function test_um_tecnico_nao_confirma_o_servico_de_outro(): void
    {
        [, $schedule] = $this->makeSchedule();
        $intruso = Vendor::factory()->create();

        $this->actingAs($intruso->user, 'api')
            ->postJson("/api/v1/vendor/schedule/{$schedule->id}/confirm-attendance")
            ->assertStatus(404);

        $this->assertNull($schedule->fresh()->vendor_confirmed_at);
    }

    public function test_nao_se_confirma_uma_ocorrencia_por_pagar(): void
    {
        [$vendor, $schedule] = $this->makeSchedule(3, false);

        $this->actingAs($vendor->user, 'api')
            ->postJson("/api/v1/vendor/schedule/{$schedule->id}/confirm-attendance")
            ->assertStatus(409);

        $this->assertNull($schedule->fresh()->vendor_confirmed_at);
    }

    public function test_o_lembrete_leva_o_tecnico_ao_ecra_onde_confirma(): void
    {
        // Sem `data` no toExpo, a push abria a app na home: o tecnico recebia
        // "confirma que vais" e nao tinha como la chegar.
        [$vendor, $schedule] = $this->makeSchedule();
        $notification = new ScheduleAttendanceReminderNotification($schedule);

        $message = $notification->toExpo($vendor->user);
        // O canal Expo faz json_encode do `data` antes de enviar (e a app
        // desserializa do outro lado — ver useNotification.tsx).
        $data = json_decode($message->toArray()['data'] ?? '{}', true);

        $this->assertSame('schedule_attendance', $data['open_type'] ?? null);
        $this->assertSame($schedule->id, $data['open_id'] ?? null);
    }
}
