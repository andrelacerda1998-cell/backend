<?php

namespace Tests\Feature;

use App\Enums\Schedule\ScheduleRecurrence;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * O que a agenda do tecnico tem de trazer para ele poder trabalhar.
 *
 * O tecnico chega a casa do cliente com o que a app lhe disse — se o payload
 * nao trouxer o que o servico inclui (e o que NAO inclui), a discussao sobre o
 * que estava combinado acontece a porta, sem arbitro. E uma marcacao que se
 * repete todas as semanas pesa de outra maneira na agenda do que uma avulsa.
 */
class VendorScheduleAgendaPayloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
    }

    private function makeAgenda(?ScheduleRecurrence $recurrence = null): array
    {
        $locale = 'pt-pt';
        $customer = User::factory()->create();
        $vendor = Vendor::factory()->create();
        $serviceType = ServicesType::factory()->create([
            'time' => 90,
            // Formato real da coluna: uma lista de itens, cada um com as suas
            // traducoes. O item sem traducao na lingua atual existe de
            // proposito — nao pode chegar a app como linha em branco.
            'includes' => [[$locale => 'Mao de obra'], [$locale => 'Deslocacao'], ['xx_XX' => 'Sem traducao']],
            'excludes' => [[$locale => 'Materiais'], [$locale => 'Obra de alvenaria']],
        ]);

        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => $serviceType->id,
            'status' => ServiceStatus::SCHEDULED,
        ]);

        Schedule::query()->create([
            'vendor_id' => $vendor->id,
            'customer_id' => $customer->id,
            'service_type_id' => $serviceType->id,
            'service_id' => $service->id,
            'scheduled_day' => Carbon::today('Europe/Lisbon')->addDays(2)->toDateString(),
            'scheduled_time_start' => '14:30:00',
            'scheduled_time_end' => '16:00:00',
            'recurrence' => $recurrence,
            'is_pending' => false,
        ]);

        return [$vendor->user, $service];
    }

    public function test_a_agenda_diz_o_que_o_servico_inclui_e_nao_inclui(): void
    {
        [$user] = $this->makeAgenda();

        $response = $this->actingAs($user, 'api')
            ->withHeaders(['Accept-Language' => 'pt-pt'])
            ->getJson('/api/v1/vendor/schedule/schedules');

        $response->assertOk();
        $serviceType = $response->json('data.0.service_type');

        $this->assertSame(['Mao de obra', 'Deslocacao'], $serviceType['includes']);
        $this->assertSame(['Materiais', 'Obra de alvenaria'], $serviceType['excludes']);
    }

    public function test_a_agenda_diz_que_a_marcacao_se_repete(): void
    {
        [$user] = $this->makeAgenda(ScheduleRecurrence::WEEKLY);

        $response = $this->actingAs($user, 'api')
            ->withHeaders(['Accept-Language' => 'pt-pt'])
            ->getJson('/api/v1/vendor/schedule/schedules');

        $response->assertOk();
        $schedule = $response->json('data.0.schedule');

        $this->assertSame('weekly', $schedule['recurrence']);
        $this->assertTrue($schedule['is_recurring']);
    }

    public function test_uma_marcacao_avulsa_nao_e_marcada_como_recorrente(): void
    {
        [$user] = $this->makeAgenda();

        $response = $this->actingAs($user, 'api')
            ->withHeaders(['Accept-Language' => 'pt-pt'])
            ->getJson('/api/v1/vendor/schedule/schedules');

        $response->assertOk();
        $schedule = $response->json('data.0.schedule');

        $this->assertNull($schedule['recurrence']);
        $this->assertFalse($schedule['is_recurring']);
    }
}
