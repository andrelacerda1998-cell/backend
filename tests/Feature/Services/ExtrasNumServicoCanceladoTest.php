<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\Gender;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Common\Services\CancelService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O DINHEIRO DOS EXTRAS QUANDO O SERVIÇO MORRE.
 *
 * Um extra é cobrado ao cliente NO MOMENTO DA APROVAÇÃO (captura imediata), e
 * só existe com o serviço em ARRIVED -- um dos estados de onde ainda se pode
 * cancelar. Antes disto o cancelamento não lhes tocava e o `CloseService`
 * (quem credita extras) nunca corre num cancelado: o cliente ficava pago, o
 * técnico sem nada, e o extra `approved`/`paid` para sempre.
 *
 * Regra (André, 02/10/2026), a seguir a mesma lógica do serviço base -- cobra-se
 * o que aconteceu, não se cobra o que não aconteceu:
 *  - PEÇA: 100% para o técnico. Comprou-a do bolso e provavelmente instalou-a.
 *  - TEMPO EXTRA: reembolso ao cliente. Não houve trabalho.
 */
class ExtrasNumServicoCanceladoTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'services', 'services_types',
        'operation_areas', 'service_extras', 'transactions', 'transfers', 'schedule',
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

        return Vendor::create(['user_id' => $user->id, 'username' => 'tec_'.$user->id, 'price_rate' => 30.0]);
    }

    private function servicoEmExecucao(Vendor $v): Service
    {
        $cliente = User::factory()->create();

        $s = new Service();
        $s->forceFill([
            'customer_id' => $cliente->id,
            'vendor_id' => $v->id,
            'quantity' => 1,
            'status' => ServiceStatus::ARRIVED,
            'payment_status' => PaymentStatus::PAID,
            'distance' => 2,
            'amount' => 4500,
            'amount_for_vendor' => 3375,
            'credit_used' => 0,
            'price_rate' => 0,
            'is_custom' => 0,
            'is_test' => 0,
            'arrived_at' => now(),
        ])->save();

        return $s->fresh();
    }

    /**
     * `not_required` = cobrado sem passar pelo gateway. É o que permite testar
     * a REGRA sem um Payshop em sandbox: o dinheiro conta como entrado, e o
     * caminho do reembolso externo fica guardado por `paymentOrder` nulo.
     */
    private function extraCobrado(Service $s, string $tipo, int $valor)
    {
        return $s->extras()->create([
            'type' => $tipo,
            'description' => $tipo === 'part' ? 'Torneira nova' : null,
            'minutes' => $tipo === 'time' ? 30 : null,
            'amount' => $valor,
            'status' => 'approved',
            'payment_status' => 'not_required',
            'charged_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------ peças

    public function test_a_peca_vai_inteira_para_o_tecnico(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoEmExecucao($v);
        $extra = $this->extraCobrado($s, 'part', 5000);   // 50,00 €

        $antes = (int) $v->user->balance;

        (new CancelService($s))->resolverExtrasAoCancelar();

        // 50,00 € inteiros, sem comissão: é o reembolso de um custo dele.
        $this->assertSame($antes + 5000, (int) $v->user->fresh()->balance);
        $this->assertNotNull($extra->fresh()->vendor_credited_at);
    }

    /** Resolver duas vezes não paga duas vezes. */
    public function test_a_peca_nao_e_creditada_duas_vezes(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoEmExecucao($v);
        $this->extraCobrado($s, 'part', 5000);

        $antes = (int) $v->user->balance;

        (new CancelService($s))->resolverExtrasAoCancelar();
        (new CancelService($s->fresh()))->resolverExtrasAoCancelar();

        $this->assertSame($antes + 5000, (int) $v->user->fresh()->balance);
    }

    // ------------------------------------------------------------ tempo extra

    public function test_o_tempo_extra_e_devolvido_e_nao_pago_ao_tecnico(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoEmExecucao($v);
        $extra = $this->extraCobrado($s, 'time', 1500);   // meia hora a 30 €/h

        $antes = (int) $v->user->balance;

        (new CancelService($s))->resolverExtrasAoCancelar();

        $this->assertSame('refunded', $extra->fresh()->payment_status);
        $this->assertNull($extra->fresh()->vendor_credited_at, 'o técnico não recebe trabalho que não fez');
        $this->assertSame($antes, (int) $v->user->fresh()->balance);
    }

    // ----------------------------------------------------------------- bordas

    /** O que nunca foi cobrado não se devolve nem se credita. */
    public function test_um_extra_que_falhou_a_cobranca_nao_mexe_em_dinheiro(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoEmExecucao($v);
        $extra = $s->extras()->create([
            'type' => 'part', 'description' => 'Peça', 'amount' => 5000,
            'status' => 'approved', 'payment_status' => 'failed',
        ]);

        $antes = (int) $v->user->balance;

        (new CancelService($s))->resolverExtrasAoCancelar();

        $this->assertSame($antes, (int) $v->user->fresh()->balance);
        $this->assertNull($extra->fresh()->vendor_credited_at);
    }

    /** Um extra recusado pelo cliente não entra nesta conta. */
    public function test_um_extra_recusado_e_ignorado(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoEmExecucao($v);
        $extra = $s->extras()->create([
            'type' => 'part', 'description' => 'Peça', 'amount' => 5000,
            'status' => 'rejected', 'payment_status' => null,
        ]);

        $antes = (int) $v->user->balance;

        (new CancelService($s))->resolverExtrasAoCancelar();

        $this->assertSame($antes, (int) $v->user->fresh()->balance);
        $this->assertNull($extra->fresh()->vendor_credited_at);
    }

    /**
     * NADA FICA PENDURADO.
     *
     * Era este o pior dos estados: um extra pago e esquecido, que ninguém sabia
     * que existia.
     */
    public function test_nao_sobra_nenhum_extra_por_resolver(): void
    {
        $v = $this->tecnico();
        $s = $this->servicoEmExecucao($v);
        $this->extraCobrado($s, 'part', 5000);
        $this->extraCobrado($s, 'time', 1500);

        $s->forceFill(['status' => ServiceStatus::CANCELED])->save();
        (new CancelService($s->fresh()))->resolverExtrasAoCancelar();

        $pendurados = \App\Models\ServiceExtra::query()
            ->where('service_id', $s->id)
            ->where('status', 'approved')
            ->whereNull('vendor_credited_at')
            ->where('payment_status', '!=', 'refunded')
            ->count();

        $this->assertSame(0, $pendurados);
    }
}
