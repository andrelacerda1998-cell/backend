<?php

namespace Tests\Feature;

use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * "Possível falta": a hora está a chegar e o técnico ainda não vai a caminho.
 *
 * É o aviso que chega antes das suspeitas — a tempo de um telefonema. O
 * relógio fica numa quarta de março de 2031, com Lisboa em UTC+0.
 */
class AdminVendorNoShowAvisosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
        config(['services.admin_api.token' => 'token-de-teste']);
        $this->travelTo(Carbon::parse('2031-03-12 11:00:00')->utc());
    }

    private function marcado(string $dia, string $hora, ServiceStatus $status = ServiceStatus::SCHEDULED, array $extra = []): Service
    {
        $service = Service::factory()->create([
            'status' => $status,
            'amount' => 5000,
            'amount_for_vendor' => 4000,
            ...$extra,
        ]);
        if (isset($extra['on_the_way_at']) || isset($extra['vendor_no_show_at'])) {
            $service->forceFill(array_intersect_key($extra, ['on_the_way_at' => 1, 'vendor_no_show_at' => 1]))->save();
        }

        // Pela tabela: o observador da agenda marcaria um lembrete na fila.
        DB::table('schedule')->insert([
            'vendor_id' => $service->vendor_id,
            'customer_id' => $service->customer_id,
            'service_type_id' => $service->services_type_id,
            'service_id' => $service->id,
            'scheduled_day' => $dia,
            'scheduled_time_start' => $hora,
            'scheduled_time_end' => Carbon::parse($hora)->addHour()->format('H:i:s'),
            'is_pending' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $service;
    }

    private function ler(string $query = ''): array
    {
        return $this->withHeaders(['Authorization' => 'Bearer token-de-teste'])
            ->getJson('/api/v1/admin/services/vendor-no-shows'.$query)
            ->assertOk()
            ->json('data');
    }

    public function test_avisa_um_quarto_de_hora_antes_e_depois_de_quem_nao_vai_a_caminho(): void
    {
        $daquiADez = $this->marcado('2031-03-12', '11:10:00');
        $haDez = $this->marcado('2031-03-12', '10:50:00');
        $aceiteSemACaminho = $this->marcado('2031-03-12', '11:05:00', ServiceStatus::ACCEPTED);
        // Fora: já vai a caminho, longe demais, passou há muito, já declarada, de teste.
        $this->marcado('2031-03-12', '11:10:00', ServiceStatus::ACCEPTED, ['on_the_way_at' => now()->subMinutes(5)]);
        $this->marcado('2031-03-12', '11:30:00');
        $this->marcado('2031-03-12', '10:40:00');
        $this->marcado('2031-03-12', '11:00:00', extra: ['vendor_no_show_at' => now()]);
        $teste = $this->marcado('2031-03-12', '11:05:00', extra: ['is_test' => true]);

        $dados = $this->ler();

        $this->assertSame([$haDez->id, $aceiteSemACaminho->id, $daquiADez->id], array_column($dados['avisos'], 'service_id'));
        $this->assertSame([-10, 5, 10], array_column($dados['avisos'], 'minutes_to_start'));
        $this->assertSame([$teste->id], array_column($this->ler('?incluir_testes=1')['avisos'], 'service_id'));
    }

    public function test_cada_aviso_tem_a_forma_das_suspeitas(): void
    {
        $this->marcado('2031-03-12', '10:55:00');

        $dados = $this->ler();
        $suspeita = $dados['suspected'][0];
        $aviso = $dados['avisos'][0];

        // A hora passou há 5 minutos: está nas duas listas, de propósito.
        $this->assertSame($suspeita['service_id'], $aviso['service_id']);
        $this->assertSame([...array_keys($suspeita), 'minutes_to_start'], array_keys($aviso));
        $this->assertSame(2000, $aviso['penalty_if_declared']);
        $this->assertNull($aviso['on_the_way_at']);
    }

    public function test_a_janela_atravessa_a_meia_noite_de_lisboa(): void
    {
        $this->travelTo(Carbon::parse('2031-03-12 23:55:00')->utc());
        $depoisDaMeiaNoite = $this->marcado('2031-03-13', '00:05:00');

        $this->assertSame([$depoisDaMeiaNoite->id], array_column($this->ler()['avisos'], 'service_id'));
    }

    public function test_as_listas_que_ja_existiam_continuam_la(): void
    {
        $dados = $this->ler();

        $this->assertSame(['suspected', 'declared', 'avisos', 'penalty_ratio'], array_keys($dados));
    }
}
