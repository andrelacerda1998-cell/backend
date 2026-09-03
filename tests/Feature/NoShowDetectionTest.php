<?php

namespace Tests\Feature;

use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Admin\NoShowOpsNotification;
use App\Notifications\Customer\ScheduleDelayedNotification;
use App\Notifications\Vendor\VendorNoShowNudgeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A rede de segurança que faltava no incidente de 13/08: detetar um agendamento
 * que passou a hora e continua por iniciar (SCHEDULED), e escalar em etapas.
 */
class NoShowDetectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    /**
     * Cria um serviço SCHEDULED com um agendamento cuja hora foi há $minutesAgo minutos.
     */
    private function makeScheduledService(int $minutesAgo, string $serviceStatus = ServiceStatus::SCHEDULED->value): Schedule
    {
        $vendor = Vendor::factory()->create();
        $customer = User::factory()->create();
        $type = ServicesType::factory()->create();
        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => $type->id,
            'status' => $serviceStatus,
        ]);

        // A hora marcada é interpretada em Europe/Lisbon pelo comando; ancoramos a
        // "hora de parede" nesse fuso para o cálculo bater certo em qualquer TZ.
        $scheduledAt = Carbon::now('Europe/Lisbon')->subMinutes($minutesAgo);

        return Schedule::create([
            'vendor_id' => $vendor->id,
            'customer_id' => $customer->id,
            'service_type_id' => $type->id,
            'service_id' => $service->id,
            'scheduled_day' => $scheduledAt->toDateString(),
            'scheduled_time_start' => $scheduledAt->format('H:i:s'),
            'scheduled_time_end' => $scheduledAt->copy()->addHour()->format('H:i:s'),
            'is_pending' => false,
        ]);
    }

    public function test_escala_as_tres_etapas_quando_passaram_mais_de_20_min(): void
    {
        Notification::fake();
        $admin = $this->makeAdmin();
        $schedule = $this->makeScheduledService(minutesAgo: 25);

        $this->artisan('services:detect-no-show')->assertSuccessful();

        Notification::assertSentTo($schedule->vendor->user, VendorNoShowNudgeNotification::class);
        Notification::assertSentTo($schedule->customer, ScheduleDelayedNotification::class);
        Notification::assertSentTo($admin, NoShowOpsNotification::class);

        $this->assertEquals(3, DB::table('service_no_show_notifications')->where('service_id', $schedule->service_id)->count());
    }

    public function test_dentro_da_margem_nao_escala(): void
    {
        Notification::fake();
        $this->makeAdmin();
        $this->makeScheduledService(minutesAgo: 5); // < 10 min

        $this->artisan('services:detect-no-show')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertEquals(0, DB::table('service_no_show_notifications')->count());
    }

    public function test_so_a_etapa_vendor_entre_10_e_15_min(): void
    {
        Notification::fake();
        $this->makeAdmin();
        $schedule = $this->makeScheduledService(minutesAgo: 12);

        $this->artisan('services:detect-no-show')->assertSuccessful();

        Notification::assertSentTo($schedule->vendor->user, VendorNoShowNudgeNotification::class);
        Notification::assertNotSentTo($schedule->customer, ScheduleDelayedNotification::class);
        $this->assertEquals(1, DB::table('service_no_show_notifications')->where('stage', 'vendor')->count());
    }

    public function test_idempotente_nao_reenvia(): void
    {
        $this->makeAdmin();
        $schedule = $this->makeScheduledService(minutesAgo: 25);

        // 1.a corrida: envia as 3
        $this->artisan('services:detect-no-show')->assertSuccessful();
        $this->assertEquals(3, DB::table('service_no_show_notifications')->count());

        // 2.a corrida: nada de novo
        Notification::fake();
        $this->artisan('services:detect-no-show')->assertSuccessful();
        Notification::assertNothingSent();
        $this->assertEquals(3, DB::table('service_no_show_notifications')->count());
    }

    public function test_agendamento_cancelado_nao_escala(): void
    {
        Notification::fake();
        $this->makeAdmin();
        $schedule = $this->makeScheduledService(minutesAgo: 25);
        $schedule->delete(); // soft delete (cancelamento)

        $this->artisan('services:detect-no-show')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_servico_ja_em_curso_nao_escala(): void
    {
        Notification::fake();
        $this->makeAdmin();
        // Técnico já marcou "A caminho" → serviço saiu de SCHEDULED.
        $this->makeScheduledService(minutesAgo: 25, serviceStatus: ServiceStatus::ACCEPTED->value);

        $this->artisan('services:detect-no-show')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
