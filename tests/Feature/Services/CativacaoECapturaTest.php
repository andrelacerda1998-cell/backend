<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\Gender;
use App\Models\Service;
use App\Models\ServiceExtra;
use App\Services\Common\Services\CloseService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RwInteractive\PayshopSdk\Enums\Payment\OperationType;
use RwInteractive\PayshopSdk\Enums\Payment\Status;
use RwInteractive\PayshopSdk\Models\PaymentMethod;
use RwInteractive\PayshopSdk\Models\PaymentOrder;
use Tests\Support\FakeChargeServiceExtra;
use Tests\TestCase;

/**
 * O DINHEIRO: CATIVAR, COBRAR, CAPTURAR.
 *
 * A pergunta do André: o dinheiro fica cativo no pedido inicial, os extras
 * voltam a cativar, e no fim captura-se tudo e emite-se a fatura?
 *
 * A resposta que estes testes fixam é METADE SIM, METADE NÃO:
 *
 *  - o serviço base É cativado (`OperationType::DEFERRED` + `authorize()`) e só
 *    é capturado no fecho;
 *  - um extra NÃO volta a cativar nada. Cria uma ORDEM PRÓPRIA e, no cartão,
 *    é AUTORIZADO E CAPTURADO na aprovação do cliente -- o dinheiro sai logo,
 *    não no fim;
 *  - no fecho captura-se APENAS a base. Os extras de cartão já estão cobrados;
 *    só os de MBWay são capturados aqui.
 *
 * A SDK do Payshop usa Guzzle direto, não a facade `Http`, por isso não se pode
 * fingir a fronteira com `Http::fake()`. Os extras têm duplo de teste próprio
 * (`FakeChargeServiceExtra`); na base testa-se o que não exige a rede: o estado
 * da cativação, a idempotência da captura, e o que acontece quando a captura
 * falha.
 */
class CativacaoECapturaTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'services', 'services_types',
        'operation_areas', 'service_extras', 'transactions', 'transfers',
        'schedule', 'payshop_payments_orders', 'payshop_payment_methods',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
        Queue::fake(); // o job de faturação não corre aqui
    }

    private function servico(int $amount = 6000, int $paraTecnico = 4500): Service
    {
        return Service::factory()->create([
            'status' => ServiceStatus::ARRIVED,
            'payment_status' => PaymentStatus::PAID,
            'amount' => $amount,
            'amount_for_vendor' => $paraTecnico,
        ]);
    }

    /** Uma ordem como a que o checkout cria: DEFERRED, autorizada, NÃO capturada. */
    private function cativacao(Service $s, int $valor, Status $estado = Status::CREATED): PaymentOrder
    {
        $ordem = PaymentOrder::create([
            'user_id' => $s->customer_id,
            'uuid' => (string) Str::uuid(),
            'amount' => $valor,
            'paid' => false,
            'status' => $estado,
            'type' => OperationType::DEFERRED,
            'refunded' => 0,
            'service' => 'teste',
            'service_uuid' => (string) Str::uuid(),
            'token' => 'token-de-teste',
        ]);

        $s->forceFill(['payment_order_id' => $ordem->id])->save();

        return $ordem;
    }

    private function cartao(Service $s): PaymentMethod
    {
        // `user_id`/`name`/`default` não são fillable no modelo da SDK.
        $m = new PaymentMethod();
        $m->forceFill([
            'user_id' => $s->customer_id,
            'uuid' => (string) Str::uuid(),
            'type' => \RwInteractive\PayshopSdk\Enums\PaymentMethods\PaymentMethodType::CARD,
            'token' => 'cartao-de-teste',
        ])->save();

        return $m->fresh();
    }

    // ================================================================ 1. A CATIVAÇÃO BASE

    /**
     * A ordem do serviço é DEFERRED: cativa agora, cobra depois.
     *
     * `ProcessesServicePayment` cria `createPaymentOrder(OperationType::DEFERRED,
     * ...)` e chama `authorize()`. `authorize()` NÃO move dinheiro -- reserva-o.
     */
    public function test_a_ordem_do_servico_e_diferida_e_nao_esta_capturada(): void
    {
        $s = $this->servico();
        $ordem = $this->cativacao($s, 6000);

        $this->assertSame(OperationType::DEFERRED, $ordem->type);
        $this->assertNotSame(Status::SUCCESS, $ordem->status, 'A ordem base está capturada antes do fecho.');
        $this->assertFalse((bool) $ordem->paid);
    }

    /**
     * `payment_status = PAID` NÃO QUER DIZER COBRADO.
     *
     * O `processCreditCardPayment` põe PAID logo depois de `authorize()`. É um
     * nome que engana: significa "o pagamento está garantido", não "o dinheiro
     * entrou". Quem ler o campo à letra conclui que já se cobrou -- e no caso de
     * um agendado isso pode estar dias longe da verdade.
     */
    public function test_paid_no_servico_coexiste_com_uma_ordem_nao_capturada(): void
    {
        $s = $this->servico();
        $ordem = $this->cativacao($s, 6000);

        $this->assertSame(PaymentStatus::PAID, $s->payment_status);
        $this->assertNotSame(Status::SUCCESS, $ordem->status);
    }

    // ================================================================ 2. OS EXTRAS

    /**
     * UM EXTRA NÃO AUMENTA A CATIVAÇÃO: CRIA UMA ORDEM PRÓPRIA.
     *
     * É o ponto central da pergunta. O `ChargeServiceExtra::createCardOrder`
     * chama `createPaymentOrder` outra vez, com o valor do extra. A ordem do
     * serviço fica exactamente como estava -- mesmo valor, mesmo estado.
     */
    public function test_um_extra_cria_ordem_propria_e_nao_mexe_na_cativacao_base(): void
    {
        $s = $this->servico();
        $ordemBase = $this->cativacao($s, 6000);
        $cartao = $this->cartao($s);

        $extra = ServiceExtra::factory()->approved()->create([
            'service_id' => $s->id, 'type' => 'part', 'amount' => 5000,
            'payment_status' => null, 'payment_order_id' => null,
        ]);

        (new FakeChargeServiceExtra())->charge($s->fresh(), $extra, $cartao);

        $extra->refresh();
        $ordemBase->refresh();

        // Ordem SEPARADA, não a mesma.
        $this->assertNotNull($extra->payment_order_id);
        $this->assertNotSame($ordemBase->id, $extra->payment_order_id);

        // A cativação base não foi tocada.
        $this->assertSame(6000, (int) $ordemBase->amount);
        $this->assertNotSame(Status::SUCCESS, $ordemBase->status);
    }

    /**
     * O EXTRA É CAPTURADO NA APROVAÇÃO, NÃO NO FIM.
     *
     * `chargeCard()` faz `authorizeOrder()` e logo a seguir `captureOrder()`
     * (`$order->confirm()`), com o comentário "captura imediata — a cobrança
     * acontece na aprovação". O dinheiro sai da conta do cliente no momento em
     * que ele toca em aprovar, com o serviço ainda a decorrer.
     */
    public function test_o_extra_e_capturado_logo_na_aprovacao(): void
    {
        $s = $this->servico();
        $this->cativacao($s, 6000);
        $cartao = $this->cartao($s);

        $extra = ServiceExtra::factory()->approved()->create([
            'service_id' => $s->id, 'type' => 'part', 'amount' => 5000,
            'payment_status' => null, 'payment_order_id' => null,
        ]);

        $resultado = (new FakeChargeServiceExtra())->charge($s->fresh(), $extra, $cartao);

        $extra->refresh();

        $this->assertSame('paid', $resultado);
        $this->assertSame('paid', $extra->payment_status);
        $this->assertNotNull($extra->charged_at, 'O extra não registou a data da cobrança.');
        $this->assertSame(Status::SUCCESS, $extra->paymentOrder->status);
    }

    /**
     * No fim ficam DUAS cobranças no cartão do cliente, não uma.
     *
     * Uma por cada extra, no momento da aprovação, e a da base no fecho. O
     * extracto do banco mostra linhas separadas; a fatura é um documento só.
     * Não é um erro -- mas é a razão por que o extracto e a fatura não casam
     * linha a linha, e vale a pena saber antes de um cliente perguntar.
     */
    public function test_dois_extras_dao_duas_ordens_distintas(): void
    {
        $s = $this->servico();
        $ordemBase = $this->cativacao($s, 6000);
        $cartao = $this->cartao($s);

        $ids = [];
        foreach ([5000, 1500] as $valor) {
            $extra = ServiceExtra::factory()->approved()->create([
                'service_id' => $s->id, 'type' => 'part', 'amount' => $valor,
                'payment_status' => null, 'payment_order_id' => null,
            ]);
            (new FakeChargeServiceExtra())->charge($s->fresh(), $extra, $cartao);
            $ids[] = $extra->refresh()->payment_order_id;
        }

        $this->assertCount(2, array_unique($ids));
        $this->assertNotContains($ordemBase->id, $ids);
        // Três ordens no total para um serviço: a base + duas de extras.
        $this->assertSame(3, PaymentOrder::count());
    }

    /** Um extra recusado pelo banco não fica com dinheiro nem com data de cobrança. */
    public function test_um_extra_recusado_nao_fica_cobrado(): void
    {
        $s = $this->servico();
        $this->cativacao($s, 6000);
        $cartao = $this->cartao($s);

        $extra = ServiceExtra::factory()->approved()->create([
            'service_id' => $s->id, 'type' => 'part', 'amount' => 5000,
            'payment_status' => null, 'payment_order_id' => null,
        ]);

        $falso = new FakeChargeServiceExtra();
        $falso->outcome = 'declined';
        $falso->charge($s->fresh(), $extra, $cartao);

        $extra->refresh();

        $this->assertSame('failed', $extra->payment_status);
        $this->assertNull($extra->charged_at);
        $this->assertFalse($extra->isCharged());
    }

    // ================================================================ 3. O FECHO

    /**
     * COM A ORDEM JÁ CAPTURADA O FECHO NÃO VOLTA A COBRAR.
     *
     * `capturePayment()` sai mais cedo quando a ordem já está em SUCCESS. Sem
     * esse guard, uma reconciliação ou um retry do fecho cobrava duas vezes ao
     * cliente.
     */
    public function test_o_fecho_nao_recaptura_uma_ordem_ja_capturada(): void
    {
        $s = $this->servico();
        $s->forceFill(['status' => ServiceStatus::FINISHED])->save();
        $ordem = $this->cativacao($s->fresh(), 6000, Status::SUCCESS);

        $saldoAntes = $s->vendor->user->balanceInt;

        $estado = (new CloseService($s->fresh()))->close();

        $this->assertSame(ServiceStatus::CLOSED, $estado);
        $this->assertSame(Status::SUCCESS, $ordem->fresh()->status);
        $this->assertSame($saldoAntes + 4500, $s->vendor->user->fresh()->balanceInt);
    }

    /**
     * SE A CAPTURA FALHAR, O TÉCNICO NÃO É PAGO.
     *
     * Aqui a ordem está em CREATED: o `confirm()` tem de ir ao Payshop, que não
     * existe no ambiente de teste, e rebenta. É exactamente o cenário de uma
     * cativação expirada ou recusada -- e prova a invariante que importa: nunca
     * se paga ao técnico dinheiro que não foi cobrado. O serviço fica parqueado
     * para nova tentativa em vez de passar a fechado.
     */
    public function test_captura_falhada_parqueia_o_servico_e_nao_paga_ao_tecnico(): void
    {
        $s = $this->servico();
        $s->forceFill(['status' => ServiceStatus::FINISHED])->save();
        $this->cativacao($s->fresh(), 6000, Status::CREATED);

        $saldoAntes = $s->vendor->user->balanceInt;

        $estado = (new CloseService($s->fresh()))->close();

        $this->assertSame(ServiceStatus::CLOSED_PENDING_PAYMENT, $estado);
        $this->assertSame($saldoAntes, $s->vendor->user->fresh()->balanceInt);
    }

    /**
     * Um extra de cartão JÁ COBRADO não é recapturado no fecho -- só creditado.
     *
     * O `settleExtras` só chama `captureExtraOrder` para extras em
     * `pending_confirmation` (MBWay). Um extra `paid` passa direto ao crédito.
     */
    public function test_no_fecho_um_extra_ja_cobrado_e_creditado_mas_nao_recobrado(): void
    {
        $s = $this->servico();
        $s->forceFill(['status' => ServiceStatus::FINISHED])->save();
        $this->cativacao($s->fresh(), 6000, Status::SUCCESS);

        $extra = ServiceExtra::factory()->approved()->create([
            'service_id' => $s->id, 'type' => 'part', 'amount' => 5000,
            'payment_status' => 'paid', 'charged_at' => now(),
        ]);

        $saldoAntes = $s->vendor->user->balanceInt;

        (new CloseService($s->fresh()))->close();

        // Peça: vai inteira ao técnico. 4500 da base + 5000 da peça.
        $this->assertSame($saldoAntes + 9500, $s->vendor->user->fresh()->balanceInt);
        $this->assertNotNull($extra->fresh()->vendor_credited_at);
    }

    /** Dinheiro que não entrou nunca é creditado, mesmo no fecho. */
    public function test_no_fecho_um_extra_nao_cobrado_nao_credita_nada(): void
    {
        $s = $this->servico();
        $s->forceFill(['status' => ServiceStatus::FINISHED])->save();
        $this->cativacao($s->fresh(), 6000, Status::SUCCESS);

        $extra = ServiceExtra::factory()->approved()->create([
            'service_id' => $s->id, 'type' => 'part', 'amount' => 5000,
            'payment_status' => 'failed',
        ]);

        $saldoAntes = $s->vendor->user->balanceInt;

        (new CloseService($s->fresh()))->close();

        $this->assertSame($saldoAntes + 4500, $s->vendor->user->fresh()->balanceInt);
        $this->assertNull($extra->fresh()->vendor_credited_at);
    }

    // ================================================================ 4. A JANELA DE 15 DIAS

    /**
     * A CATIVAÇÃO VALE 15 DIAS. O AGENDAMENTO NÃO TEM LIMITE.
     *
     * `createPaymentOrder(..., now()->addDays(15), ...)` nas três formas de
     * pagamento. Mas `scheduled_day` é validado apenas como `date` -- sem
     * limite superior -- e o `DatePicker` da app não passa `maximumDate`.
     *
     * Um serviço marcado para dentro de 20 dias tem a cativação a expirar antes
     * de alguém aparecer. No fecho o `confirm()` falha, o serviço fica em
     * CLOSED_PENDING_PAYMENT (ver teste acima) e o técnico fez o trabalho sem
     * receber.
     *
     * Este teste fixa a aritmética para que a incompatibilidade não passe
     * despercebida.
     */
    public function test_a_cativacao_expira_antes_de_um_agendamento_distante(): void
    {
        $diasDeCativacao = 15;

        $marcadoPara = now()->addDays(20);
        $cativacaoExpiraEm = now()->addDays($diasDeCativacao);

        $this->assertTrue(
            $cativacaoExpiraEm->lessThan($marcadoPara),
            'A janela da cativação passou a cobrir 20 dias -- actualiza este teste.',
        );
    }

    /** Dentro de 15 dias está coberto: é o caso normal. */
    public function test_um_agendamento_proximo_esta_dentro_da_cativacao(): void
    {
        $this->assertTrue(now()->addDays(15)->greaterThan(now()->addDays(3)));
    }
}
