<?php

namespace Tests\Feature\Endpoints;

use App\Enums\Services\AddressType;
use App\Models\GeneralSettings\ServicesType;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * O cálculo do preço no checkout, e a agenda de um técnico.
 *
 * O `/calculate` é o número que o cliente vê antes de pagar. Passou a aceitar
 * o dia e a hora do trabalho (para a sobretaxa horária ser a do serviço e não
 * a do checkout) — e esses campos novos precisam de estar fixados, incluindo
 * o facto de serem opcionais, senão uma app antiga deixa de conseguir pagar.
 */
class ClienteCalculoEagendaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    private function cenario(): array
    {
        $customer = User::factory()->create();
        $customer->addresses()->create([
            'street_name' => 'Rua A', 'street_number' => '1', 'postal_code' => '1000-001',
            'city' => 'Lisboa', 'state' => 'Lisboa', 'country' => 'Portugal',
            'latitude' => 38.7223, 'longitude' => -9.1393, 'main_address' => true,
        ]);

        $vendor = Vendor::factory()->create();
        // Num agendado a distancia mede-se da morada de agenda do
        // profissional; sem ela nao ha preco possivel.
        $vendor->user->addresses()->create([
            'address_type' => AddressType::SCHEDULE_ADDRESS,
            'street_name' => 'Rua B', 'street_number' => '2', 'postal_code' => '2560-363',
            'city' => 'Torres Vedras', 'state' => 'Lisboa', 'country' => 'Portugal',
            'latitude' => 39.0918, 'longitude' => -9.2588,
        ]);

        return [$customer, $vendor, ServicesType::factory()->create(['time' => 60])];
    }

    public function test_o_calculo_responde_sem_dia_nem_hora(): void
    {
        [$customer, $vendor, $tipo] = $this->cenario();

        // Opcionais de propósito: uma app antiga não os envia e tem de
        // continuar a conseguir chegar ao checkout.
        $this->actingAs($customer, 'api')
            ->postJson('/api/v1/customer/services/calculate', [
                'vendor_id' => $vendor->id,
                'service_type' => $tipo->id,
                'scheduled' => true,
            ])
            ->assertOk();
    }

    public function test_o_calculo_aceita_o_dia_e_a_hora_do_trabalho(): void
    {
        [$customer, $vendor, $tipo] = $this->cenario();

        $this->actingAs($customer, 'api')
            ->postJson('/api/v1/customer/services/calculate', [
                'vendor_id' => $vendor->id,
                'service_type' => $tipo->id,
                'scheduled' => true,
                'scheduled_day' => Carbon::today('Europe/Lisbon')->addDays(3)->toDateString(),
                'scheduled_time_start' => '10:00',
            ])
            ->assertOk();
    }

    public function test_a_hora_sem_o_dia_e_recusada(): void
    {
        [$customer, $vendor, $tipo] = $this->cenario();

        // Uma hora sem dia não identifica instante nenhum, e cotar com meia
        // informação é pior do que recusar.
        $this->actingAs($customer, 'api')
            ->postJson('/api/v1/customer/services/calculate', [
                'vendor_id' => $vendor->id,
                'service_type' => $tipo->id,
                'scheduled' => true,
                'scheduled_time_start' => '10:00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_day']);
    }

    public function test_um_dia_mal_formado_e_recusado(): void
    {
        [$customer, $vendor, $tipo] = $this->cenario();

        $this->actingAs($customer, 'api')
            ->postJson('/api/v1/customer/services/calculate', [
                'vendor_id' => $vendor->id,
                'service_type' => $tipo->id,
                'scheduled' => true,
                'scheduled_day' => 'para a semana',
                'scheduled_time_start' => '10:00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_day']);
    }

    public function test_o_calculo_exige_tecnico_e_tipo_de_servico(): void
    {
        $this->actingAs(User::factory()->create(), 'api')
            ->postJson('/api/v1/customer/services/calculate', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vendor_id', 'service_type']);
    }

    public function test_um_tecnico_que_nao_existe_e_recusado(): void
    {
        [$customer, , $tipo] = $this->cenario();

        $this->actingAs($customer, 'api')
            ->postJson('/api/v1/customer/services/calculate', [
                'vendor_id' => 999999,
                'service_type' => $tipo->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['vendor_id']);
    }

    public function test_um_tecnico_sem_morada_nao_rebenta_o_checkout(): void
    {
        [$customer, , $tipo] = $this->cenario();
        $semMorada = Vendor::factory()->create();

        // O `vendor_id` vem do cliente: pedir o preco de um profissional que
        // ainda nao completou o perfil tem de dar 422, nao 500.
        $this->actingAs($customer, 'api')
            ->postJson('/api/v1/customer/services/calculate', [
                'vendor_id' => $semMorada->id,
                'service_type' => $tipo->id,
                'scheduled' => true,
            ])
            ->assertStatus(422);
    }

    public function test_a_agenda_de_um_tecnico_responde(): void
    {
        [$customer, $vendor] = $this->cenario();

        $this->actingAs($customer, 'api')
            ->getJson("/api/v1/customer/schedule/vendor/{$vendor->id}")
            ->assertOk();
    }

    public function test_a_agenda_de_um_tecnico_exige_autenticacao(): void
    {
        $this->getJson('/api/v1/customer/schedule/vendor/1')->assertStatus(401);
    }
}
