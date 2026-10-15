<?php

namespace Tests\Feature\Payments;

use App\Enums\Services\CandidateStatus;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\ServiceAcceptedEvent;
use App\Jobs\Services\MbwayPaymentCheckJob;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Models\User;
use App\Models\Vendor;
use App\Notifications\Customer\Mbway\PaymentSuccessNotification;
use App\Notifications\Vendor\ServiceWonNotification;
use App\Services\Matching\MatchingService;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Um cliente que também tem perfil de técnico paga como os outros.
 *
 * A relação `Service::customer` exclui quem tem perfil de técnico
 * (`whereDoesntHave('vendor')`, desde o import inicial). Essa pessoa entra na
 * app do cliente se for admin, ou se já tinha sessão quando passou a técnico.
 * Para ela, `customer` vinha null, e o pagamento confirmado rebentava em três
 * sítios:
 *
 *  · o `payment-status` respondia 500 — o ecrã "Estamos a aguardar o
 *    pagamento" ignora-o e fica à espera até desistir (reproduzido a
 *    15/10/2026, depois de um MB Way autorizado ficar preso nesse ecrã);
 *  · o técnico escolhido nunca recebia "O trabalho é teu": o evento do
 *    cliente rebentava antes, no mesmo try;
 *  · o cliente não recebia a confirmação do MB Way.
 */
class EstadoDoPagamentoDeQuemTambemETecnicoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GenderSeeder::class);
        Notification::fake();
    }

    private function clienteQueTambemETecnico(): User
    {
        return Vendor::factory()->create()->user;
    }

    private function pedidoDe(User $cliente, array $atributos = []): Service
    {
        return Service::factory()->create([
            'customer_id' => $cliente->id,
            'services_type_id' => ServicesType::factory(),
        ] + $atributos);
    }

    /** O que já funcionava continua a funcionar. */
    public function test_cliente_so_cliente_recebe_o_pagamento_confirmado(): void
    {
        $cliente = User::factory()->create();
        $pedido = $this->pedidoDe($cliente, ['status' => ServiceStatus::ACCEPTED, 'payment_status' => PaymentStatus::PAID]);

        $this->actingAs($cliente, 'api')
            ->getJson("/api/v1/customer/services/{$pedido->id}/payment-status")
            ->assertSuccessful()
            ->assertJsonPath('data.service.id', $pedido->id);
    }

    public function test_cliente_que_tambem_e_tecnico_recebe_o_pagamento_confirmado(): void
    {
        $cliente = $this->clienteQueTambemETecnico();
        $pedido = $this->pedidoDe($cliente, ['status' => ServiceStatus::ACCEPTED, 'payment_status' => PaymentStatus::PAID]);

        $this->assertNull($pedido->customer, 'a relação customer continua a excluir quem tem perfil de técnico');

        $this->actingAs($cliente, 'api')
            ->getJson("/api/v1/customer/services/{$pedido->id}/payment-status")
            ->assertSuccessful()
            ->assertJsonPath('data.service.id', $pedido->id)
            ->assertJsonPath('data.service.customer.name', $cliente->name);
    }

    public function test_o_tecnico_sabe_que_ganhou_mesmo_que_o_cliente_tambem_seja_tecnico(): void
    {
        Event::fake([ServiceAcceptedEvent::class]);

        $cliente = $this->clienteQueTambemETecnico();
        $escolhido = Vendor::factory()->create();
        $pedido = $this->pedidoDe($cliente, [
            'status' => ServiceStatus::ACCEPTED,
            'payment_status' => PaymentStatus::PAID,
            'vendor_id' => $escolhido->id,
        ]);

        app(MatchingService::class)->avisarQueGanhou($pedido);

        Notification::assertSentTo($escolhido->user, ServiceWonNotification::class);
        Event::assertDispatched(ServiceAcceptedEvent::class);
    }

    /** O MB Way confirmado pela verificação atrasada: pago, e o cliente sabe. */
    public function test_mb_way_confirmado_avisa_o_cliente_que_tambem_e_tecnico(): void
    {
        Event::fake([ServiceAcceptedEvent::class]);

        $cliente = $this->clienteQueTambemETecnico();
        $escolhido = Vendor::factory()->create();
        $pedido = $this->pedidoDe($cliente, [
            'status' => ServiceStatus::AWAITING_PAYMENT,
            'payment_status' => PaymentStatus::PENDING,
            'vendor_id' => $escolhido->id,
        ]);
        ServiceCandidate::create([
            'service_id' => $pedido->id,
            'vendor_id' => $escolhido->id,
            'rank' => 1,
            'wave' => 1,
            'status' => CandidateStatus::SELECTED,
            'quoted_amount' => 2034,
            'quoted_amount_for_vendor' => 1525,
            'quoted_distance' => 2.4,
            'notified_at' => now()->subMinutes(3),
            'responded_at' => now()->subMinutes(2),
        ]);

        // Sem ordem de pagamento ligada, o job confirma diretamente — é o
        // mesmo `confirmPayment()` que corre depois de o Payshop dizer que sim.
        (new MbwayPaymentCheckJob($pedido))->handle();

        $pedido->refresh();
        $this->assertSame(PaymentStatus::PAID, $pedido->payment_status);
        $this->assertSame(ServiceStatus::ACCEPTED, $pedido->status);
        Notification::assertSentTo($cliente, PaymentSuccessNotification::class);
        Notification::assertSentTo($escolhido->user, ServiceWonNotification::class);
    }

    /** A app do técnico abre o pedido de um cliente que também é técnico. */
    public function test_o_payload_do_tecnico_traz_o_cliente_que_tambem_e_tecnico(): void
    {
        $cliente = $this->clienteQueTambemETecnico();
        $pedido = $this->pedidoDe($cliente, ['status' => ServiceStatus::ACCEPTED, 'payment_status' => PaymentStatus::PAID]);

        $this->assertSame($cliente->name, $pedido->formatDataForVendor()['customer']['name'] ?? null);
    }
}
