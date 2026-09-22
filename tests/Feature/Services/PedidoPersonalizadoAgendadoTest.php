<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Common\Services\MaterializePendingSchedule;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Um pedido personalizado que o cliente agenda e paga.
 *
 * Um personalizado não tem tipo de serviço — `services_type_id` é null de
 * propósito, e a duração vem do backoffice em `custom_duration_minutes`. Dois
 * sítios não contavam com isso:
 *
 *  - MaterializePendingSchedule procurava o tipo, não encontrava, limpava a
 *    intenção de agendamento e saía em silêncio. O cliente pagava e a marcação
 *    nunca nascia.
 *  - ServiceRequestedData desreferenciava `serviceType->operationArea` sem
 *    guarda. A lista de pedidos pendentes do técnico passava a dar 500 — e não
 *    só neste pedido: em TODOS, porque a lista rebenta inteira.
 */
class PedidoPersonalizadoAgendadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GenderSeeder::class);
        Notification::fake();
        Queue::fake();
    }

    /**
     * @return array{0: User, 1: Vendor, 2: Service}
     */
    private function personalizadoPago(?int $duracao = 90, bool $agendado = true): array
    {
        $customer = User::factory()->create();
        $vendor = Vendor::factory()->create();

        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => null,
            'status' => ServiceStatus::PENDING,
            'payment_status' => PaymentStatus::PAID,
            'amount' => 12000,
            'amount_for_vendor' => 9000,
        ]);

        $service->forceFill([
            'is_custom' => true,
            'custom_description' => 'Trocar a fechadura da porta da rua.',
            'custom_duration_minutes' => $duracao,
            'quantity' => 1,
            'pending_schedule_data' => $agendado ? [
                'scheduled' => true,
                'schedule' => [
                    'scheduled_day' => Carbon::today('Europe/Lisbon')->addWeek()->toDateString(),
                    'scheduled_time_start' => '14:30',
                ],
            ] : null,
        ])->save();

        return [$customer, $vendor, $service->refresh()];
    }

    public function test_a_lista_de_pendentes_do_tecnico_nao_rebenta_com_um_personalizado(): void
    {
        [, $vendor, $service] = $this->personalizadoPago();

        // É a lista por onde o técnico responde de facto aos pedidos. Sem
        // guarda no serializador, um único personalizado deitava-a abaixo
        // inteira — o técnico deixava de ver este E todos os outros.
        $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/vendor/services/pending/all')
            ->assertSuccessful();

        $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/vendor/services/pending')
            ->assertSuccessful();

        $this->assertSame(ServiceStatus::PENDING, $service->fresh()->status);
    }

    /**
     * O payload do técnico leva a duração já feita.
     *
     * Do lado da app o objeto do serviço é `any`, por isso o TypeScript não
     * verifica nada — quem tem de garantir o contrato é este teste.
     */
    public function test_o_payload_do_tecnico_leva_a_duracao_real(): void
    {
        [, $vendor, $service] = $this->personalizadoPago(duracao: 90);

        $lista = $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/vendor/services/pending/all')
            ->assertSuccessful()
            ->json('data.services');

        $encontrado = collect($lista)->firstWhere('id', $service->id);

        $this->assertNotNull($encontrado, 'o pedido tem de aparecer na lista do técnico');
        $this->assertSame(90, $encontrado['duration_minutes']);
    }

    public function test_um_personalizado_agendado_e_pago_passa_a_gerar_a_marcacao(): void
    {
        [, $vendor, $service] = $this->personalizadoPago(duracao: 90);

        app(MaterializePendingSchedule::class)->handle($service);

        $schedule = $vendor->schedules()->where('service_id', $service->id)->first();

        $this->assertNotNull($schedule, 'o cliente pagou um agendamento: a marcação tem de existir');
        $this->assertSame('14:30:00', Carbon::parse($schedule->scheduled_time_start)->format('H:i:s'));

        // A duração vem do backoffice, não de um tipo de serviço que não existe.
        $this->assertSame(
            '16:00:00',
            Carbon::parse($schedule->scheduled_time_end)->format('H:i:s'),
            '90 minutos a partir das 14:30',
        );
        $this->assertTrue((bool) $schedule->is_pending, 'continua à espera da resposta do técnico');
    }

    /**
     * O caso em que o backoffice ainda não fez a parte dele.
     *
     * Na prática não devia acontecer — a ação de despacho exige a duração antes
     * de o pedido sequer chegar a um técnico. Mas se acontecer, não se inventa
     * um número: uma duração adivinhada bloqueia a agenda de alguém pelo tempo
     * errado. Fica por materializar, e a intenção de agendamento fica INTACTA
     * para alguém poder definir a duração e reprocessar.
     */
    public function test_um_personalizado_sem_duracao_nao_inventa_uma_nem_perde_o_agendamento(): void
    {
        [, $vendor, $service] = $this->personalizadoPago(duracao: null);

        app(MaterializePendingSchedule::class)->handle($service);

        $this->assertSame(
            0,
            $vendor->schedules()->where('service_id', $service->id)->count(),
            'sem duração definida não se cria marcação nenhuma',
        );
        $this->assertNotNull(
            $service->fresh()->pending_schedule_data,
            'a intenção de agendamento não pode ser deitada fora: é a única prova do que o cliente comprou',
        );
    }
}
