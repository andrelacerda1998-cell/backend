<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\Gender;
use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Common\Services\AcceptService;
use App\Services\Common\Services\RefuseService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Aceitar um pedido imediato liberta os outros pedidos imediatos.
 *
 * Um pedido imediato é "podes AGORA?". Com dois na mão, nada impedia o
 * profissional de dizer que sim aos dois -- os dois estavam PENDING e os dois
 * aceitavam. Comprometia-se a estar em dois sítios à mesma hora, e o segundo
 * cliente só descobria quando ninguém aparecesse.
 *
 * O que estes testes protegem:
 *  - o segundo pedido imediato desaparece, e o dinheiro volta ao cliente;
 *  - um AGENDADO para outro dia NÃO é cancelado (seria tirar-lhe trabalho);
 *  - os pedidos de OUTRO profissional não são tocados;
 *  - e nada disto conta como recusa dele.
 */
class NaoAceitaDoisAoMesmoTempoTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'services', 'services_types',
        'operation_areas', 'transactions', 'transfers', 'schedule',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
        Queue::fake();
    }

    private function tecnico(): Vendor
    {
        $user = User::factory()->create();

        return Vendor::create(['user_id' => $user->id, 'username' => 'tec_'.$user->id]);
    }

    private function pedido(Vendor $vendor, int $credito = 0): Service
    {
        $cliente = User::factory()->create();

        $servico = new Service();
        $servico->forceFill([
            'customer_id' => $cliente->id,
            'vendor_id' => $vendor->id,
            'quantity' => 1,
            'status' => ServiceStatus::PENDING,
            'payment_status' => PaymentStatus::PAID,
            'distance' => 2,
            'amount' => 4500,
            'amount_for_vendor' => 3375,
            'credit_used' => $credito,
            'price_rate' => 0,
            'is_custom' => 0,
            'is_test' => 0,
        ])->save();

        return $servico->fresh();
    }

    public function test_aceitar_um_liberta_o_outro_pedido_imediato(): void
    {
        $vendor = $this->tecnico();
        $a = $this->pedido($vendor);
        $b = $this->pedido($vendor);

        app(AcceptService::class)->accept($a);

        $this->assertSame(ServiceStatus::ACCEPTED, $a->fresh()->status);
        $this->assertSame(ServiceStatus::REFUSED, $b->fresh()->status);
        $this->assertSame(RefuseService::MOTIVO_OCUPADO, $b->fresh()->status_justification);
    }

    /** O dinheiro do segundo cliente volta para ele. */
    public function test_devolve_o_dinheiro_do_pedido_libertado(): void
    {
        $vendor = $this->tecnico();
        $a = $this->pedido($vendor);
        $b = $this->pedido($vendor, credito: 2000);
        $clienteB = $b->customer;

        $this->assertSame(0, (int) $clienteB->balance);

        app(AcceptService::class)->accept($a);

        $this->assertSame(2000, (int) $clienteB->fresh()->balance);
    }

    /**
     * Um AGENDADO para outro dia sobrevive.
     *
     * É a parte fácil de errar: cancelar tudo o que está pendente era mais
     * simples de escrever e tirava-lhe trabalho que ele podia mesmo fazer.
     */
    public function test_nao_cancela_um_pedido_agendado(): void
    {
        $vendor = $this->tecnico();
        $imediato = $this->pedido($vendor);
        $agendado = $this->pedido($vendor);

        Schedule::forceCreate([
            'service_id' => $agendado->id,
            'vendor_id' => $vendor->id,
            'customer_id' => $agendado->customer_id,
            'scheduled_day' => now()->addDays(3)->toDateString(),
            'scheduled_time_start' => '15:00:00',
            'scheduled_time_end' => '16:00:00',
            'is_pending' => false,
        ]);

        app(AcceptService::class)->accept($imediato);

        $this->assertSame(ServiceStatus::PENDING, $agendado->fresh()->status);
    }

    /** Aceitar um AGENDADO não liberta nada: não ocupa o "agora". */
    public function test_aceitar_um_agendado_nao_liberta_os_imediatos(): void
    {
        $vendor = $this->tecnico();
        $agendado = $this->pedido($vendor);
        Schedule::forceCreate([
            'service_id' => $agendado->id,
            'vendor_id' => $vendor->id,
            'customer_id' => $agendado->customer_id,
            'scheduled_day' => now()->addDays(3)->toDateString(),
            'scheduled_time_start' => '15:00:00',
            'scheduled_time_end' => '16:00:00',
            'is_pending' => false,
        ]);
        $imediato = $this->pedido($vendor);

        app(AcceptService::class)->accept($agendado->fresh());

        $this->assertSame(ServiceStatus::PENDING, $imediato->fresh()->status);
    }

    /** Os pedidos de outro profissional não são tocados. */
    public function test_nao_toca_nos_pedidos_de_outro_profissional(): void
    {
        $vendorA = $this->tecnico();
        $vendorB = $this->tecnico();

        $meu = $this->pedido($vendorA);
        $doOutro = $this->pedido($vendorB);

        app(AcceptService::class)->accept($meu);

        $this->assertSame(ServiceStatus::PENDING, $doOutro->fresh()->status);
    }

    /** Ser libertado não é recusar: a taxa de aceitação não cai. */
    public function test_ser_libertado_nao_estraga_a_taxa_de_aceitacao(): void
    {
        $vendor = $this->tecnico();
        $a = $this->pedido($vendor);
        $this->pedido($vendor);

        app(AcceptService::class)->accept($a);
        $a->fresh()->forceFill(['status' => ServiceStatus::CLOSED])->save();

        $resposta = $this->actingAs($vendor->user, 'api')->getJson('/api/v1/vendor/stats');
        $resposta->assertOk();

        $this->assertSame(100, $resposta->json('data.acceptance_rate'));
    }

    /** Com três na mão, aceitar um liberta os outros dois. */
    public function test_liberta_todos_os_outros_e_nao_so_um(): void
    {
        $vendor = $this->tecnico();
        $a = $this->pedido($vendor);
        $b = $this->pedido($vendor);
        $c = $this->pedido($vendor);

        app(AcceptService::class)->accept($a);

        $this->assertSame(ServiceStatus::REFUSED, $b->fresh()->status);
        $this->assertSame(ServiceStatus::REFUSED, $c->fresh()->status);
    }
}
