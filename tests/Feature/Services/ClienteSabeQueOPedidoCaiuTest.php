<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\ServiceTimeoutEvent as CommonServiceTimeoutEvent;
use App\Events\Vendor\Services\ServiceTimeoutEvent as VendorServiceTimeoutEvent;
use App\Jobs\Services\CancelJobWithoutReactionJob;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Customer\ServiceTimedOutNotification;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Quando o profissional não responde a tempo, o cliente tem de saber.
 *
 * O cancelamento por prazo só emitia dois eventos de broadcast — chegavam a
 * quem tivesse a app aberta no canal certo, e a mais ninguém. O prazo do
 * agendado é de 20 minutos: ninguém os passa a olhar para um cronómetro. Quem
 * fechasse a app ficava a acreditar que o pedido continuava vivo, e só
 * descobria que não ao voltar — talvez horas depois, com o problema por
 * resolver e sem ter tentado outra coisa entretanto.
 */
class ClienteSabeQueOPedidoCaiuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GenderSeeder::class);

        Event::fake([CommonServiceTimeoutEvent::class, VendorServiceTimeoutEvent::class]);
        Notification::fake();
    }

    /**
     * @return array{0: User, 1: Service}
     */
    private function pedidoSemResposta(bool $agendado): array
    {
        $customer = User::factory()->create();
        $vendor = Vendor::factory()->create();
        $serviceType = ServicesType::factory()->create([
            'time' => 90,
            'name' => ['pt-pt' => 'Canalização', 'en' => 'Plumbing'],
        ]);

        $service = Service::factory()->create([
            'customer_id' => $customer->id,
            'vendor_id' => $vendor->id,
            'services_type_id' => $serviceType->id,
            'status' => ServiceStatus::PENDING,
            'payment_status' => PaymentStatus::PAID,
        ]);

        if ($agendado) {
            Schedule::query()->create([
                'vendor_id' => $vendor->id,
                'customer_id' => $customer->id,
                'service_type_id' => $serviceType->id,
                'service_id' => $service->id,
                'scheduled_day' => Carbon::today('Europe/Lisbon')->addWeek()->toDateString(),
                'scheduled_time_start' => '14:30:00',
                'scheduled_time_end' => '16:00:00',
                'is_pending' => true,
            ]);
        }

        return [$customer, $service->fresh()];
    }

    public function test_o_cliente_e_avisado_quando_o_pedido_cai_por_falta_de_resposta(): void
    {
        [$customer, $service] = $this->pedidoSemResposta(agendado: false);

        (new CancelJobWithoutReactionJob($service))->handle();

        Notification::assertSentTo($customer, ServiceTimedOutNotification::class);
    }

    public function test_o_aviso_vai_por_push_e_fica_no_historico(): void
    {
        [$customer, $service] = $this->pedidoSemResposta(agendado: false);

        (new CancelJobWithoutReactionJob($service))->handle();

        // Só o broadcast não chegava a quem tinha a app fechada. A push é o que
        // resolve isso; o 'database' é o que deixa o desfecho no histórico.
        Notification::assertSentTo(
            $customer,
            ServiceTimedOutNotification::class,
            function (ServiceTimedOutNotification $aviso) use ($customer) {
                return $aviso->via($customer) === ['expo', 'database'];
            },
        );
    }

    public function test_o_texto_distingue_um_agendamento_de_um_pedido_imediato(): void
    {
        [$clienteAgendado, $servicoAgendado] = $this->pedidoSemResposta(agendado: true);
        [$clienteImediato, $servicoImediato] = $this->pedidoSemResposta(agendado: false);

        (new CancelJobWithoutReactionJob($servicoAgendado))->handle();
        (new CancelJobWithoutReactionJob($servicoImediato))->handle();

        $corpo = function (User $cliente): string {
            $capturado = null;
            Notification::assertSentTo(
                $cliente,
                ServiceTimedOutNotification::class,
                function (ServiceTimedOutNotification $aviso) use ($cliente, &$capturado) {
                    $capturado = $aviso->toArray($cliente)['body'];

                    return true;
                },
            );

            return (string) $capturado;
        };

        $this->assertStringContainsString('agendamento', $corpo($clienteAgendado));
        $this->assertStringNotContainsString('agendamento', $corpo($clienteImediato));

        // O tipo de serviço entra no texto nos dois casos: "o teu pedido" sem
        // dizer de quê obriga o cliente a abrir a app para saber o que caiu.
        $this->assertStringContainsString('Canalização', $corpo($clienteAgendado));
        $this->assertStringContainsString('Canalização', $corpo($clienteImediato));
    }

    public function test_um_pedido_que_o_tecnico_aceitou_nao_gera_aviso_de_queda(): void
    {
        [$customer, $service] = $this->pedidoSemResposta(agendado: false);
        $service->update(['status' => ServiceStatus::SCHEDULED]);

        (new CancelJobWithoutReactionJob($service->fresh()))->handle();

        Notification::assertNotSentTo($customer, ServiceTimedOutNotification::class);
    }
}
