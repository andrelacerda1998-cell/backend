<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Jobs\Services\CreateInvoiceJob;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SEGUNDA BATERIA: os casos-limite das faturas.
 *
 * A primeira (BateriaDeFaturasTest) prova que o payload está certo no caminho
 * normal. Esta ataca as bordas -- morada de faturação, serviços de teste,
 * agendados, crédito promocional, vouchers, e a corrida entre a emissão da
 * fatura e a cobrança de um extra.
 *
 * Vários destes testes DOCUMENTAM o comportamento actual em vez de o aprovar.
 * Estão marcados como tal: servem para que uma mudança futura seja uma decisão
 * e não um acidente.
 */
class FaturasCasosLimiteTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'services', 'services_types',
        'operation_areas', 'service_extras', 'transactions', 'transfers', 'schedule',
        'user_billing_infos', 'media',
    ];

    private const FATURA_ID = 555001;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        config(['services.invoiceExpress.vat' => 23]);
        config(['services.invoiceExpress.product_name' => 'Serviço Piquet']);
        \App\Models\GeneralSettings\Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();

        Http::fake([
            '*invoice_receipts.json*' => Http::response(['invoice_receipt' => ['id' => self::FATURA_ID]]),
            '*change-state.json*' => Http::response(['invoice' => ['id' => self::FATURA_ID]]),
            '*api/pdf/*' => Http::response(['output' => ['pdfUrl' => 'https://exemplo.invalido/f.pdf']]),
            '*' => Http::response(['success' => true]),
        ]);
    }

    private function servico(array $campos = [], array $cliente = []): Service
    {
        $u = User::factory()->create(['nif' => '500000000']);
        $v = Vendor::create(['user_id' => $u->id, 'username' => 'tec_'.$u->id]);
        $v->forceFill(['auth_token' => 'tok', 'invoice_workspace' => 'ws', 'invoice_account_id' => 1])->save();

        $c = User::factory()->create(array_merge(['name' => 'Ana Silva', 'nif' => '200000000'], $cliente));
        $tipo = ServicesType::factory()->create(['name' => ['pt-pt' => 'Canalização', 'en' => 'Plumbing']]);

        $s = new Service();
        $s->forceFill(array_merge([
            'customer_id' => $c->id, 'vendor_id' => $v->id, 'services_type_id' => $tipo->id,
            'quantity' => 1, 'status' => ServiceStatus::CLOSED, 'payment_status' => PaymentStatus::PAID,
            'distance' => 2, 'amount' => 6000, 'amount_for_vendor' => 4500, 'credit_used' => 0,
            'price_rate' => 0, 'is_custom' => 0, 'is_test' => 0,
        ], $campos))->save();

        return $s->fresh();
    }

    private function emitir(Service $s): array
    {
        (new \App\Services\InvoiceXpress\InvoiceVendorService($s->vendor))->createServiceInvoice($s);

        $corpo = null;
        Http::recorded(function ($p) use (&$corpo) {
            if (str_contains($p->url(), 'invoice_receipts.json')) {
                $corpo = $p->data();
            }
        });
        $this->assertNotNull($corpo, 'Nenhuma fatura saiu.');

        return $corpo['invoice'];
    }

    private function totalBruto(array $fatura): float
    {
        return round(array_sum(array_map(fn ($l) => $l['unit_price'], $fatura['items'])) * 1.23, 2);
    }

    // ================================================================ 1. ARREDONDAMENTO

    /**
     * O `unit_price` QUE SAI É LÍQUIDO, COM CASAS DECIMAIS INFINITAS.
     *
     * `item()` manda `($centimos / 100) / 1,23`. Num serviço de 25,00 € isso é
     * 20,325203252032520... Se a InvoiceXpress guardar o preço unitário com
     * DUAS casas (20,33), o bruto que ela recalcula é 25,01 € -- um cêntimo
     * acima do que o cliente pagou, num documento fiscal.
     *
     * Este teste não acusa a InvoiceXpress: fixa a aritmética, para que fique
     * registado quanto está em jogo e para que uma correção futura (mandar o
     * valor já arredondado a 3 casas, ou mandar o bruto) tenha com que medir.
     *
     * Com TRÊS casas o erro desaparece em todos os 200 000 valores de 0,01 € a
     * 2 000 €. Com duas, quebram 18,7% -- incluindo 5, 15, 18, 25 e 35 €.
     */
    public function test_o_liquido_de_valores_correntes_nao_sobrevive_a_duas_casas(): void
    {
        $arredondaPara = function (int $centimos, int $casas): int {
            $liquido = round(($centimos / 100) / 1.23, $casas);

            return (int) round(round($liquido * 1.23, 2) * 100);
        };

        // Preços perfeitamente normais da Piquet que saem errados a 2 casas.
        foreach ([500, 1500, 1800, 2500, 3500] as $centimos) {
            $this->assertNotSame(
                $centimos,
                $arredondaPara($centimos, 2),
                number_format($centimos / 100, 2).' € sobreviveu a 2 casas -- revê o comentário deste teste.',
            );

            // A 3 casas volta ao cêntimo exacto. É a saída, se for preciso.
            $this->assertSame($centimos, $arredondaPara($centimos, 3));
        }
    }

    /** O que sai hoje tem mesmo mais de 2 casas: não é hipótese, é o payload. */
    public function test_o_payload_leva_o_liquido_com_todas_as_casas(): void
    {
        $fatura = $this->emitir($this->servico(['amount' => 2500]));

        $unitario = $fatura['items'][0]['unit_price'];

        $this->assertNotSame(round($unitario, 2), $unitario, 'O líquido saiu já arredondado a 2 casas.');
        $this->assertSame(25.00, $this->totalBruto($fatura));
    }

    // ================================================================ 2. QUEM VAI NA FATURA

    /** A morada de faturação do cliente é a que vai na fatura. */
    public function test_a_morada_de_faturacao_entra_na_fatura(): void
    {
        $s = $this->servico();
        $s->customer->billingInfo()->create([
            'name' => 'Empresa XPTO Lda',
            'nif' => '509999999',
            'address' => 'Rua das Flores 10',
            'postal_code' => '1000-001',
            'locality' => 'Lisboa',
        ]);

        $fatura = $this->emitir($s->fresh());

        $this->assertSame('Rua das Flores 10', $fatura['client']['address']);
        $this->assertSame('1000-001', $fatura['client']['postal_code']);
        $this->assertSame('Lisboa', $fatura['client']['city']);
        $this->assertSame('Portugal', $fatura['client']['country']);
    }

    /**
     * DOCUMENTA UM DEFEITO: o nome e o NIF da morada de faturação são ignorados.
     *
     * O `createServiceInvoice` faz `$address = $user->billingInfo` e usa esse
     * registo SÓ como morada. O nome vem de `$user->name` e o NIF de
     * `$service->nif ?? $user->nif`.
     *
     * Ou seja: um cliente que preencheu a faturação com a empresa dele recebe
     * uma fatura com o nome e o NIF PESSOAIS e a morada DA EMPRESA. A morada de
     * faturação existe exactamente para faturar a outra entidade, e é a única
     * parte dela que não é usada.
     *
     * Quando se corrigir, este teste tem de passar a esperar 'Empresa XPTO Lda'
     * e '509999999'.
     */
    public function test_o_nome_e_o_nif_da_morada_de_faturacao_sao_ignorados(): void
    {
        $s = $this->servico();
        $s->customer->billingInfo()->create([
            'name' => 'Empresa XPTO Lda',
            'nif' => '509999999',
            'address' => 'Rua das Flores 10',
            'postal_code' => '1000-001',
            'locality' => 'Lisboa',
        ]);

        $fatura = $this->emitir($s->fresh());

        // O estado actual: entidade pessoal, morada da empresa.
        $this->assertSame('Ana Silva', $fatura['client']['name']);
        $this->assertSame('200000000', $fatura['client']['fiscal_id']);
        $this->assertSame('Rua das Flores 10', $fatura['client']['address']);
    }

    // ================================================================ 3. SERVIÇOS DE TESTE

    /** Um serviço de teste não gera documento fiscal nenhum. */
    public function test_um_servico_de_teste_nao_emite_fatura(): void
    {
        Queue::fake();
        $s = $this->servico(['status' => ServiceStatus::FINISHED, 'is_test' => 1]);

        $s->status = ServiceStatus::CLOSED;
        $s->save();

        Queue::assertNotPushed(CreateInvoiceJob::class);
    }

    /** E um serviço a sério gera. É o contraste que dá valor ao teste acima. */
    public function test_um_servico_real_emite_fatura(): void
    {
        Queue::fake();
        $s = $this->servico(['status' => ServiceStatus::FINISHED, 'is_test' => 0]);

        $s->status = ServiceStatus::CLOSED;
        $s->save();

        Queue::assertPushed(CreateInvoiceJob::class);
    }

    // ================================================================ 4. AGENDADOS

    /**
     * DOCUMENTA UM RISCO FISCAL: a data da fatura é a do PEDIDO, não a da execução.
     *
     * `generateInvoicePayload($service->created_at, ...)`. Num serviço imediato
     * as duas datas coincidem. Num AGENDADO não: o cliente marca a 25 de
     * setembro para dia 2 de outubro, e a fatura sai datada de 25 de setembro
     * -- antes de o serviço ter sido prestado, e num MÊS e período de IVA
     * diferentes daquele em que o dinheiro entrou.
     *
     * Confirmar com o contabilista antes de mexer: a data correcta é
     * provavelmente a da prestação (o fecho), não a do pedido.
     */
    public function test_a_fatura_de_um_agendado_usa_a_data_do_pedido(): void
    {
        $pedidoEm = now()->subDays(7)->startOfDay();
        $s = $this->servico();
        $s->forceFill(['created_at' => $pedidoEm])->save();
        Schedule::create([
            'service_id' => $s->id,
            'vendor_id' => $s->vendor_id,
            'customer_id' => $s->customer_id,
            'service_type_id' => $s->services_type_id,
            'scheduled_day' => now()->format('Y-m-d'),
            'scheduled_time_start' => '10:00',
            'scheduled_time_end' => '11:00',
        ]);

        $fatura = $this->emitir($s->fresh());

        $this->assertSame($pedidoEm->format('d-m-Y'), $fatura['date']);
        $this->assertNotSame(
            now()->format('d-m-Y'),
            $fatura['date'],
            'A fatura passou a usar a data da execução -- actualiza este teste e o comentário.',
        );
    }

    // ================================================================ 5. CRÉDITO E VOUCHERS

    /**
     * O crédito promocional NÃO reduz a fatura -- e está certo assim.
     *
     * O crédito é uma forma de PAGAMENTO, não um desconto: o serviço valeu
     * 60 €, o cliente pagou 20 € com saldo e 40 € no cartão, e a fatura tem de
     * dizer 60 €. Reduzir aqui seria faturar a menos do que se vendeu.
     */
    public function test_o_credito_promocional_nao_reduz_a_fatura(): void
    {
        $fatura = $this->emitir($this->servico(['amount' => 6000, 'credit_used' => 2000]));

        $this->assertSame(60.00, $this->totalBruto($fatura));
    }

    /**
     * Um voucher SIM reduz -- porque reduz o preço, não a forma de pagamento.
     *
     * O `OpenServiceController` grava `original_amount` e `discount_amount` à
     * parte e põe em `amount` o valor JÁ descontado. A fatura usa `amount`:
     * fatura-se o que se vendeu.
     */
    public function test_um_voucher_reduz_a_fatura_porque_reduz_o_preco(): void
    {
        $fatura = $this->emitir($this->servico([
            'amount' => 4500, 'original_amount' => 6000, 'discount_amount' => 1500,
        ]));

        $this->assertSame(45.00, $this->totalBruto($fatura));
    }

    // ================================================================ 6. A CORRIDA DO EXTRA

    /**
     * DOCUMENTA UMA CORRIDA: um extra cobrado DEPOIS da fatura nunca é faturado.
     *
     * O `CreateInvoiceJob` corre 30 segundos depois do fecho. Um extra cuja
     * cobrança ainda estava `pending` nesse instante fica de fora -- e o guard
     * de idempotência (`invoice_id` já preenchido) garante que uma segunda
     * passagem não o acrescenta. O dinheiro entra mais tarde e já não há
     * documento onde caiba.
     *
     * Trinta segundos é curto para um 3DS. Não é hipotético.
     */
    public function test_um_extra_cobrado_depois_da_fatura_fica_de_fora_para_sempre(): void
    {
        $s = $this->servico();
        $extra = $s->extras()->create([
            'type' => 'part', 'description' => 'Torneira', 'amount' => 5000,
            'status' => 'approved', 'payment_status' => 'pending',
        ]);

        // Fatura emitida enquanto a cobrança do extra ainda não fechou.
        $fatura = $this->emitir($s->fresh());
        $this->assertCount(1, $fatura['items']);
        $this->assertSame(60.00, $this->totalBruto($fatura));

        // O 3DS termina e o dinheiro entra.
        $extra->update(['payment_status' => 'paid', 'charged_at' => now()]);

        // Correr o job outra vez não emite nada: o serviço já tem fatura.
        Http::fake([
            '*invoice_receipts.json*' => Http::response(['invoice_receipt' => ['id' => 999]]),
            '*' => Http::response(['success' => true]),
        ]);
        (new CreateInvoiceJob($s->fresh()))->handle();
        Http::assertNothingSent();

        // 50 € cobrados ao cliente sem nenhuma linha de fatura.
        $this->assertSame(self::FATURA_ID, (int) $s->fresh()->invoice_id);
    }

    // ================================================================ 7. VALORES LIMITE

    /** Um serviço inteiramente pago por voucher continua a precisar de documento. */
    public function test_um_servico_de_zero_euros_emite_uma_linha_de_zero(): void
    {
        $fatura = $this->emitir($this->servico(['amount' => 0, 'amount_for_vendor' => 0]));

        $this->assertCount(1, $fatura['items']);
        $this->assertSame(0.0, $this->totalBruto($fatura));
    }

    /** Muitos extras: nada se perde nem se trunca pelo caminho. */
    public function test_vinte_extras_saem_todos_na_fatura(): void
    {
        $s = $this->servico(['amount' => 1000]);
        for ($i = 1; $i <= 20; $i++) {
            $s->extras()->create([
                'type' => $i % 2 ? 'part' : 'time',
                'description' => 'Peça '.$i, 'minutes' => 10,
                'amount' => 100, 'status' => 'approved',
                'payment_status' => 'paid', 'charged_at' => now(),
            ]);
        }

        $fatura = $this->emitir($s->fresh());

        $this->assertCount(21, $fatura['items']);
        $this->assertSame(30.00, $this->totalBruto($fatura)); // 10 + 20 x 1
    }

    /** Cada linha leva o nome de produto configurado: é o que a AT vê. */
    public function test_cada_linha_leva_o_produto_configurado_e_quantidade_um(): void
    {
        $s = $this->servico();
        $s->extras()->create([
            'type' => 'part', 'description' => 'Peça', 'amount' => 1000,
            'status' => 'approved', 'payment_status' => 'paid', 'charged_at' => now(),
        ]);

        $fatura = $this->emitir($s->fresh());

        foreach ($fatura['items'] as $linha) {
            $this->assertSame('Serviço Piquet', $linha['name']);
            $this->assertSame(1, $linha['quantity']);
            $this->assertSame('service', $linha['unit']);
        }
    }
}
