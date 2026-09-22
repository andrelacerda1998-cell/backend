<?php

namespace Tests\Feature\Services;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Dizer "tenho interesse" reserva o horário.
 *
 * Uma reserva já paga mas ainda por aceitar não contava como serviço aberto, e
 * uma candidatura aceite não contava para nada: o profissional continuava
 * "disponível", um segundo cliente passava o portão e pagava, e ficavam dois
 * pagamentos para a mesma hora da mesma pessoa.
 *
 * A reserva dura o que a candidatura durar e cai sozinha quando ele deixa de
 * estar em `accepted` — porque recusou, porque o cliente escolheu outro, ou
 * porque o prazo passou.
 */
class ReservaDeHorarioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GenderSeeder::class);
        Event::fake();
        Notification::fake();
    }

    /**
     * @return array{0: Vendor, 1: Service, 2: ServiceCandidate}
     */
    private function interesseNum(string $hora = '14:00', int $minutos = 60, ?Carbon $expiraEm = null): array
    {
        $customer = User::factory()->create();
        $vendor = Vendor::factory()->create();
        $serviceType = ServicesType::factory()->create(['time' => $minutos]);

        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'vendor_id' => null,
            'services_type_id' => $serviceType->id,
            'status' => ServiceStatus::MATCHING,
            'payment_status' => PaymentStatus::PENDING,
        ]);

        $service->forceFill([
            'quantity' => 1,
            'pending_schedule_data' => [
                'scheduled' => true,
                'schedule' => [
                    'scheduled_day' => $this->dia()->toDateString(),
                    'scheduled_time_start' => $hora,
                ],
            ],
        ])->save();

        $candidate = ServiceCandidate::query()->create([
            'service_id' => $service->id,
            'vendor_id' => $vendor->id,
            'rank' => 1,
            'wave' => 1,
            'status' => CandidateStatus::ACCEPTED,
            'expires_at' => $expiraEm ?? now()->addMinutes(20),
        ]);

        return [$vendor, $service->refresh(), $candidate];
    }

    private function dia(): Carbon
    {
        return Carbon::today('Europe/Lisbon')->addWeek();
    }

    private function janela(string $inicio, int $minutos): array
    {
        $start = Carbon::parse($this->dia()->toDateString().' '.$inicio);

        return [$start, $start->copy()->addMinutes($minutos)];
    }

    public function test_quem_disse_que_tem_interesse_deixa_de_estar_livre_nessa_hora(): void
    {
        [$vendor] = $this->interesseNum('14:00', 60);
        [$inicio, $fim] = $this->janela('14:00', 60);

        $this->assertFalse(
            $vendor->hasFreeSlot($inicio, $fim),
            'a hora está assumida enquanto o cliente decide',
        );
    }

    public function test_a_reserva_so_apanha_a_hora_reservada(): void
    {
        [$vendor] = $this->interesseNum('14:00', 60);
        [$inicio, $fim] = $this->janela('16:00', 60);

        $this->assertTrue(
            $vendor->hasFreeSlot($inicio, $fim),
            'reservar as 14h não pode tirar-lhe as 16h',
        );
    }

    public function test_a_reserva_cobre_o_trabalho_todo_e_nao_so_a_primeira_unidade(): void
    {
        [$vendor, $service] = $this->interesseNum('14:00', 60);
        $service->forceFill(['quantity' => 3])->save();
        [$inicio, $fim] = $this->janela('16:00', 60);

        // Três unidades de uma hora ocupam das 14 às 17.
        $this->assertFalse(
            $vendor->fresh()->hasFreeSlot($inicio, $fim),
            'a reserva tem de cobrir as três horas que o cliente pediu',
        );
    }

    public function test_recusar_liberta_a_hora(): void
    {
        [$vendor, , $candidate] = $this->interesseNum('14:00', 60);
        [$inicio, $fim] = $this->janela('14:00', 60);

        $candidate->update(['status' => CandidateStatus::DECLINED]);

        $this->assertTrue($vendor->fresh()->hasFreeSlot($inicio, $fim));
    }

    public function test_o_cliente_escolher_outro_liberta_a_hora(): void
    {
        [$vendor, , $candidate] = $this->interesseNum('14:00', 60);
        [$inicio, $fim] = $this->janela('14:00', 60);

        $candidate->update(['status' => CandidateStatus::LOST]);

        $this->assertTrue($vendor->fresh()->hasFreeSlot($inicio, $fim));
    }

    public function test_a_reserva_morre_com_o_prazo_do_proprio_pedido(): void
    {
        // Sem mecanismo novo a libertá-la: a reserva dura o que a candidatura
        // durar. Passado o prazo, a hora volta a ser dele.
        [$vendor] = $this->interesseNum('14:00', 60, expiraEm: now()->subMinute());
        [$inicio, $fim] = $this->janela('14:00', 60);

        $this->assertTrue($vendor->hasFreeSlot($inicio, $fim));
    }

    /**
     * O caso que partiu dois testes do fluxo de seleção quando a reserva foi
     * introduzida: ao escolher o profissional, o servidor reverifica se a hora
     * está livre — e encontrava a reserva que aquele MESMO pedido tinha criado.
     * O cliente escolhia e era recusado por causa da própria escolha.
     */
    public function test_o_pedido_nao_se_bloqueia_a_si_proprio(): void
    {
        [$vendor, $service] = $this->interesseNum('14:00', 60);
        [$inicio, $fim] = $this->janela('14:00', 60);

        $this->assertFalse($vendor->hasFreeSlot($inicio, $fim), 'para outro pedido, a hora está ocupada');
        $this->assertTrue(
            $vendor->hasFreeSlot($inicio, $fim, $service->id),
            'para o próprio pedido, a hora tem de continuar livre',
        );
    }

    public function test_um_pedido_imediato_nao_reserva_hora_nenhuma(): void
    {
        [$vendor, $service] = $this->interesseNum('14:00', 60);
        $service->forceFill(['pending_schedule_data' => null])->save();
        [$inicio, $fim] = $this->janela('14:00', 60);

        // Um imediato não tem hora marcada para reservar — acontece agora.
        $this->assertTrue($vendor->fresh()->hasFreeSlot($inicio, $fim));
    }
}
