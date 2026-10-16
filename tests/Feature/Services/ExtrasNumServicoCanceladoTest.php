<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\ServiceExtra;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Common\Services\CancellationPolicy;
use App\Services\Common\Services\CancelService;
use Database\Seeders\GenderSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Models\PaymentOrder;
use Tests\TestCase;

/**
 * O dinheiro dos extras quando o serviço é cancelado com o técnico no local.
 *
 * Um extra é cobrado ao cliente quando ele o aprova, e só existe com o serviço
 * em ARRIVED, de onde ainda se pode cancelar. O cancelamento não lhes tocava, e
 * o fecho (quem credita extras) nunca corre num cancelado: o cliente ficava sem
 * o dinheiro, o técnico sem nada, e o extra pago para sempre.
 *
 * Regra do André (02/10/2026), portada do backend#136: a PEÇA vai inteira para
 * o técnico (comprou-a e provavelmente instalou-a); o TEMPO EXTRA devolve-se ao
 * cliente (não foi trabalhado).
 */
class ExtrasNumServicoCanceladoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(GenderSeeder::class);
        Notification::fake();
        Queue::fake();

        // Nunca bater no sandbox real do Paylands: uma porta sem ninguém, que
        // também serve para exercitar o "o gateway falhou".
        config([
            'payshop-sdk.environment' => 'sandbox',
            'payshop-sdk.api_endpoint.sandbox' => 'http://127.0.0.1:9/',
            'payshop-sdk.connect_timeout' => 0.25,
            'payshop-sdk.timeout' => 0.25,
        ]);
    }

    private function noLocal(): Service
    {
        return Service::factory()->create([
            'customer_id' => User::factory()->create()->id,
            'vendor_id' => Vendor::factory()->create()->id,
            'services_type_id' => ServicesType::factory()->create(['time' => 60])->id,
            'status' => ServiceStatus::ARRIVED,
            'payment_status' => PaymentStatus::PAID,
            'amount' => 5000,
            'amount_for_vendor' => 3750,
            'credit_used' => 0,
            'arrived_at' => now(),
        ]);
    }

    /** Uma ordem de pagamento já capturada, sem tocar na rede. */
    private function ordemCapturada(int $valor, int $cliente): PaymentOrder
    {
        return PaymentOrder::query()->create([
            'user_id' => $cliente,
            'uuid' => (string) Str::uuid(),
            'amount' => $valor,
            'paid' => true,
            'status' => Status::SUCCESS,
            'type' => OperationType::DEFERRED,
            'refunded' => 0,
            'service' => 'piquet-testes',
            'service_uuid' => (string) Str::uuid(),
            'token' => (string) Str::uuid(),
            'ip' => '127.0.0.1',
        ]);
    }

    /**
     * Um extra aprovado e cobrado. `not_required` conta como cobrado sem passar
     * pelo gateway: é o que deixa testar a regra sem Payshop.
     */
    private function extra(Service $servico, string $tipo, int $valor, array $atributos = []): ServiceExtra
    {
        return $servico->extras()->create([
            'type' => $tipo,
            'description' => $tipo === 'part' ? 'Torneira nova' : null,
            'minutes' => $tipo === 'time' ? 30 : null,
            'amount' => $valor,
            'status' => 'approved',
            'payment_status' => 'not_required',
            'charged_at' => now(),
            ...$atributos,
        ]);
    }

    private function saldo(Service $servico): int
    {
        return (int) $servico->vendor->user->fresh()->balance;
    }

    public function test_o_cliente_cancela_no_local_a_peca_vai_para_o_tecnico_e_o_tempo_volta(): void
    {
        $servico = $this->noLocal();
        $servico->forceFill(['payment_order_id' => $this->ordemCapturada(5000, $servico->customer_id)->id])->save();
        $peca = $this->extra($servico, 'part', 4000);
        $tempo = $this->extra($servico, 'time', 1500);
        $antes = $this->saldo($servico);

        (new CancelService($servico->fresh()))->cancelOpenService();

        // A metade do serviço base (a regra de sempre) mais a peça inteira.
        $this->assertSame(ServiceStatus::CANCELED, $servico->fresh()->status);
        $this->assertSame($antes + CancellationPolicy::split(5000)['vendor'] + 4000, $this->saldo($servico));
        $this->assertNotNull($peca->fresh()->vendor_credited_at);
        $this->assertSame('refunded', $tempo->fresh()->payment_status);
        $this->assertNull($tempo->fresh()->vendor_credited_at, 'o técnico não recebe tempo que não trabalhou');
    }

    /** Quando é o técnico a largar o serviço no local, recebe a peça e mais nada. */
    public function test_o_tecnico_cancela_no_local_recebe_so_a_peca(): void
    {
        $servico = $this->noLocal();
        $this->extra($servico, 'part', 4000);
        $tempo = $this->extra($servico, 'time', 1500);
        $antes = $this->saldo($servico);

        (new CancelService($servico))->vendorCancelService();

        $this->assertSame(ServiceStatus::CANCELED, $servico->fresh()->status);
        $this->assertSame($antes + 4000, $this->saldo($servico));
        $this->assertSame('refunded', $tempo->fresh()->payment_status);
    }

    public function test_resolver_duas_vezes_nao_paga_duas_vezes(): void
    {
        $servico = $this->noLocal();
        $this->extra($servico, 'part', 4000);
        $antes = $this->saldo($servico);

        (new CancelService($servico))->resolverExtrasAoCancelar();
        (new CancelService($servico->fresh()))->resolverExtrasAoCancelar();

        $this->assertSame($antes + 4000, $this->saldo($servico));
    }

    /** O que nunca foi cobrado, ou o cliente recusou, não mexe em dinheiro. */
    public function test_o_que_nao_foi_cobrado_fica_como_estava(): void
    {
        $servico = $this->noLocal();
        $falhada = $this->extra($servico, 'part', 4000, ['payment_status' => 'failed', 'charged_at' => null]);
        $porConfirmar = $this->extra($servico, 'time', 1500, ['payment_status' => 'pending_confirmation', 'charged_at' => null]);
        $recusada = $this->extra($servico, 'part', 4000, ['status' => 'rejected', 'payment_status' => null, 'charged_at' => null]);
        $antes = $this->saldo($servico);

        (new CancelService($servico))->vendorCancelService();

        $this->assertSame($antes, $this->saldo($servico));
        $this->assertNull($falhada->fresh()->vendor_credited_at);
        $this->assertSame('pending_confirmation', $porConfirmar->fresh()->payment_status);
        $this->assertNull($recusada->fresh()->vendor_credited_at);
    }

    /**
     * O reembolso é uma chamada ao Payshop. Se falhar, o cancelamento, que já
     * está feito e comunicado, não se desfaz: o extra fica pago, para se
     * reconciliar, e o erro fica reportado.
     */
    public function test_se_o_reembolso_do_tempo_falhar_o_cancelamento_mantem_se(): void
    {
        $servico = $this->noLocal();
        $ordem = $this->ordemCapturada(1500, $servico->customer_id);
        $tempo = $this->extra($servico, 'time', 1500, ['payment_status' => 'paid', 'payment_order_id' => $ordem->id]);

        (new CancelService($servico))->vendorCancelService();

        $this->assertSame(ServiceStatus::CANCELED, $servico->fresh()->status);
        $this->assertSame('paid', $tempo->fresh()->payment_status);
    }

    /** Nada fica pendurado: cada extra cobrado acaba creditado ou devolvido. */
    public function test_nao_sobra_nenhum_extra_cobrado_por_resolver(): void
    {
        $servico = $this->noLocal();
        $this->extra($servico, 'part', 4000);
        $this->extra($servico, 'time', 1500);
        $this->extra($servico, 'time', 3000);

        (new CancelService($servico))->vendorCancelService();

        $pendurados = $servico->extras()->where('status', 'approved')->get()
            ->filter(fn (ServiceExtra $e) => $e->isCharged() && $e->vendor_credited_at === null);

        $this->assertCount(0, $pendurados);
    }
}
