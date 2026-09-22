<?php

namespace Tests\Feature\Endpoints;

use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Agenda, dos dois lados.
 *
 * Uma marcacao e um compromisso entre duas pessoas: quem a cria, quem a ve e
 * quem a cancela tem de ser sempre o dono. Um dia bloqueado por engano tira o
 * tecnico das ondas de convite sem ele perceber porque parou de receber
 * trabalho.
 */
class AgendamentosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    private function comMorada(User $customer): void
    {
        $customer->addresses()->create([
            'street_name' => 'Rua A', 'street_number' => '1', 'postal_code' => '1000-001',
            'city' => 'Lisboa', 'state' => 'Lisboa', 'country' => 'Portugal',
            'latitude' => 38.7, 'longitude' => -9.1, 'main_address' => true,
        ]);
    }

    private function marcacao(?User $customer = null, ?Vendor $vendor = null): Schedule
    {
        $customer ??= User::factory()->create();
        $vendor ??= Vendor::factory()->create();
        $tipo = ServicesType::factory()->create(['time' => 60]);

        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => $tipo->id,
            'status' => ServiceStatus::SCHEDULED,
        ]);

        return Schedule::create([
            'vendor_id' => $vendor->id,
            'customer_id' => $customer->id,
            'service_type_id' => $tipo->id,
            'service_id' => $service->id,
            'scheduled_day' => Carbon::today('Europe/Lisbon')->addDays(5)->toDateString(),
            'scheduled_time_start' => '10:00:00',
            'scheduled_time_end' => '11:00:00',
            'is_pending' => false,
        ]);
    }

    // -------------------------------------------------- agenda do cliente

    public function test_o_cliente_ve_a_sua_agenda(): void
    {
        $customer = User::factory()->create();
        $this->marcacao($customer);

        $this->actingAs($customer, 'api')->getJson('/api/v1/customer/schedule')->assertOk();
    }

    public function test_a_agenda_do_cliente_exige_autenticacao(): void
    {
        $this->getJson('/api/v1/customer/schedule')->assertStatus(401);
    }

    public function test_o_cliente_abre_os_detalhes_de_uma_marcacao_sua(): void
    {
        $customer = User::factory()->create();
        $this->comMorada($customer);
        $marcacao = $this->marcacao($customer);

        $this->actingAs($customer, 'api')
            ->getJson("/api/v1/customer/schedule/details/{$marcacao->id}")
            ->assertOk();
    }

    /**
     * Apagar a unica morada promove outra a principal — mas se nao houver
     * outra, o cliente fica com zero. As marcacoes que ele ja tinha nao podem
     * deixar de abrir por causa disso.
     */
    public function test_os_detalhes_abrem_mesmo_sem_morada_principal(): void
    {
        $customer = User::factory()->create();
        $marcacao = $this->marcacao($customer);

        $this->assertNull($customer->mainAddress(), 'o cenario so vale se nao houver mesmo morada');

        $this->actingAs($customer, 'api')
            ->getJson("/api/v1/customer/schedule/details/{$marcacao->id}")
            ->assertOk();
    }

    public function test_o_cliente_cancela_uma_marcacao_sua(): void
    {
        $customer = User::factory()->create();
        $marcacao = $this->marcacao($customer);

        $this->actingAs($customer, 'api')
            ->postJson("/api/v1/customer/schedule/{$marcacao->id}/cancel")
            ->assertOk();
    }

    public function test_um_cliente_nao_cancela_a_marcacao_de_outro(): void
    {
        $marcacao = $this->marcacao();

        $this->actingAs(User::factory()->create(), 'api')
            ->postJson("/api/v1/customer/schedule/{$marcacao->id}/cancel")
            ->assertStatus(404);

        $this->assertDatabaseHas('schedule', ['id' => $marcacao->id, 'deleted_at' => null]);
    }

    public function test_cancelar_marcacao_exige_autenticacao(): void
    {
        $marcacao = $this->marcacao();

        $this->postJson("/api/v1/customer/schedule/{$marcacao->id}/cancel")->assertStatus(401);
    }

    // ------------------------------------------------- agenda do tecnico

    public function test_o_tecnico_abre_os_detalhes_de_uma_marcacao_sua(): void
    {
        $vendor = Vendor::factory()->create();
        $marcacao = $this->marcacao(null, $vendor);

        $this->actingAs($vendor->user, 'api')
            ->getJson("/api/v1/vendor/schedule/details/{$marcacao->id}")
            ->assertOk();
    }

    public function test_o_tecnico_ve_os_pedidos_pendentes(): void
    {
        $this->actingAs(Vendor::factory()->create()->user, 'api')
            ->getJson('/api/v1/vendor/schedule/pending-schedules')
            ->assertOk();
    }

    public function test_as_definicoes_de_agenda_respondem(): void
    {
        $vendor = Vendor::factory()->create();

        $this->actingAs($vendor->user, 'api')
            ->getJson("/api/v1/vendor/schedule/settings/{$vendor->user->id}")
            ->assertOk();
    }

    // --------------------------------------------------- dias indisponiveis

    public function test_o_tecnico_bloqueia_e_desbloqueia_um_dia(): void
    {
        $vendor = Vendor::factory()->create();
        $dia = Carbon::today('Europe/Lisbon')->addDays(3)->toDateString();

        $this->actingAs($vendor->user, 'api')
            ->postJson('/api/v1/vendor/schedule/unavailable-days', ['day' => $dia, 'reason' => 'Ferias'])
            ->assertOk();

        $r = $this->actingAs($vendor->user, 'api')->getJson('/api/v1/vendor/schedule/unavailable-days');
        $r->assertOk();

        $this->actingAs($vendor->user, 'api')
            ->deleteJson("/api/v1/vendor/schedule/unavailable-days/{$dia}")
            ->assertOk();

        $this->assertSame(0, $vendor->unavailableDays()->whereDate('day', $dia)->count());
    }

    public function test_nao_se_bloqueia_um_dia_que_ja_passou(): void
    {
        $vendor = Vendor::factory()->create();

        // Marcar o passado nao muda nada e so sujava a lista.
        $this->actingAs($vendor->user, 'api')
            ->postJson('/api/v1/vendor/schedule/unavailable-days', [
                'day' => Carbon::today('Europe/Lisbon')->subDay()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['day']);
    }

    public function test_bloquear_o_mesmo_dia_duas_vezes_nao_duplica(): void
    {
        $vendor = Vendor::factory()->create();
        $dia = Carbon::today('Europe/Lisbon')->addDays(4)->toDateString();

        foreach (['Ferias', 'Consulta'] as $motivo) {
            $this->actingAs($vendor->user, 'api')
                ->postJson('/api/v1/vendor/schedule/unavailable-days', ['day' => $dia, 'reason' => $motivo])
                ->assertOk();
        }

        $this->assertSame(1, $vendor->unavailableDays()->whereDate('day', $dia)->count());
        $this->assertSame('Consulta', $vendor->unavailableDays()->whereDate('day', $dia)->first()->reason);
    }

    public function test_o_dia_bloqueado_de_um_tecnico_nao_afeta_outro(): void
    {
        $dono = Vendor::factory()->create();
        $outro = Vendor::factory()->create();
        $dia = Carbon::today('Europe/Lisbon')->addDays(6)->toDateString();

        $this->actingAs($dono->user, 'api')
            ->postJson('/api/v1/vendor/schedule/unavailable-days', ['day' => $dia])
            ->assertOk();

        // O DELETE identifica o dia pela data, nao por um id: sem o scope do
        // vendor, um tecnico apagava a folga de outro.
        $this->actingAs($outro->user, 'api')
            ->deleteJson("/api/v1/vendor/schedule/unavailable-days/{$dia}")
            ->assertOk();

        $this->assertSame(1, $dono->unavailableDays()->whereDate('day', $dia)->count(), 'a folga do dono tem de sobreviver');
    }

    public function test_um_dia_mal_formado_e_recusado(): void
    {
        $this->actingAs(Vendor::factory()->create()->user, 'api')
            ->postJson('/api/v1/vendor/schedule/unavailable-days', ['day' => 'amanha'])
            ->assertStatus(422);
    }

    public function test_dias_indisponiveis_exige_autenticacao(): void
    {
        $this->getJson('/api/v1/vendor/schedule/unavailable-days')->assertStatus(401);
    }

    // ------------------------------------------------------ disponibilidade

    public function test_a_disponibilidade_de_um_tecnico_e_consultavel(): void
    {
        $vendor = Vendor::factory()->create();
        $tipo = ServicesType::factory()->create(['time' => 60]);

        $this->getJson("/api/v1/customer/schedule/vendors/{$vendor->id}/availability?service_type_id={$tipo->id}")
            ->assertOk();
    }

    public function test_a_disponibilidade_sem_saber_o_servico_e_recusada(): void
    {
        // Sem tipo nao ha duracao, e sem duracao nao ha slots que facam
        // sentido — mas tem de ser 400, nao 500.
        $vendor = Vendor::factory()->create();

        $this->getJson("/api/v1/customer/schedule/vendors/{$vendor->id}/availability")
            ->assertStatus(400);
    }
}
