<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Jobs\Services\CreateCancellationInvoiceJob;
use App\Jobs\Services\CreateInvoiceJob;
use App\Jobs\Services\CreateVendorCancellationInvoiceJob;
use App\Models\GeneralSettings\Gender;
use App\Models\Service;
use App\Models\GeneralSettings\ServicesType;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * BATERIA ÀS FATURAS EMITIDAS.
 *
 * O FaturaComExtrasTest lê o array de linhas por reflexão. Este lê o JSON QUE
 * SAI PARA A INVOICEXPRESS -- é esse que vira documento fiscal na AT, e é o
 * único sítio onde um erro de unidades ou de IVA se torna irreversível.
 *
 * Tudo com `Http::fake()`: nada toca na InvoiceXpress a sério.
 */
class BateriaDeFaturasTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = [
        'users', 'wallets', 'vendors', 'services', 'services_types',
        'operation_areas', 'service_extras', 'transactions', 'transfers', 'schedule',
        'users_billing_info', 'media',
    ];

    /** O que a InvoiceXpress responderia. */
    private const FATURA_ID = 987654;

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        config(['services.invoiceExpress.vat' => 23]);
        config(['services.invoiceExpress.product_name' => 'Serviço Piquet']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();

        Http::fake([
            '*invoice_receipts.json*' => Http::response(['invoice_receipt' => ['id' => self::FATURA_ID]]),
            '*change-state.json*' => Http::response(['invoice' => ['id' => self::FATURA_ID, 'status' => 'finalized']]),
            '*api/pdf/*' => Http::response(['output' => ['pdfUrl' => 'https://exemplo.invalido/f.pdf']]),
            '*' => Http::response(['success' => true]),
        ]);
    }

    private function tecnico(): Vendor
    {
        $user = User::factory()->create(['nif' => '500000000']);
        $v = Vendor::create(['user_id' => $user->id, 'username' => 'tec_'.$user->id]);
        $v->forceFill([
            'auth_token' => 'token-de-teste',
            'invoice_workspace' => 'ws-de-teste',
            'invoice_account_id' => 42,
        ])->save();

        return $v->fresh();
    }

    private function servico(array $campos = [], ?Vendor $tecnico = null): Service
    {
        $tecnico ??= $this->tecnico();
        $cliente = User::factory()->create(['name' => 'Cliente Teste', 'nif' => '200000000']);

        $tipo = ServicesType::factory()->create(['name' => ['pt-pt' => 'Canalização', 'en' => 'Plumbing']]);

        $s = new Service();
        $s->forceFill(array_merge([
            'customer_id' => $cliente->id,
            'vendor_id' => $tecnico->id,
            'services_type_id' => $tipo->id,
            'quantity' => 1,
            'status' => ServiceStatus::CLOSED,
            'payment_status' => PaymentStatus::PAID,
            'distance' => 2,
            'amount' => 6000,
            'amount_for_vendor' => 4500,
            'credit_used' => 0,
            'price_rate' => 0,
            'is_custom' => 0,
            'is_test' => 0,
        ], $campos))->save();

        return $s->fresh();
    }

    private function extra(Service $s, array $campos): void
    {
        $s->extras()->create(array_merge([
            'status' => 'approved',
            'payment_status' => 'paid',
            'charged_at' => now(),
        ], $campos));
    }

    /** Emite a fatura e devolve o corpo do POST que saiu. */
    private function emitir(Service $s): array
    {
        (new \App\Services\InvoiceXpress\InvoiceVendorService($s->vendor))->createServiceInvoice($s);

        $corpo = null;
        Http::recorded(function ($pedido) use (&$corpo) {
            if (str_contains($pedido->url(), 'invoice_receipts.json')) {
                $corpo = $pedido->data();
            }
        });

        $this->assertNotNull($corpo, 'Nenhum POST de fatura saiu.');

        return $corpo['invoice'];
    }

    /** Bruto de uma linha: o `unit_price` é LÍQUIDO, o IVA é do documento. */
    private function brutoDaLinha(array $linha): float
    {
        return round($linha['unit_price'] * 1.23, 2);
    }

    private function totalBruto(array $fatura): float
    {
        return round(array_sum(array_map(fn ($l) => $l['unit_price'], $fatura['items'])) * 1.23, 2);
    }

    // ---------------------------------------------------------------- A: o documento

    /** O IVA tem de ser 23% no documento, não por linha. */
    public function test_a_taxa_do_documento_e_23_por_cento(): void
    {
        $fatura = $this->emitir($this->servico());

        $this->assertSame('1.23', $fatura['rate']);
        $this->assertSame('EUR', $fatura['currency_code']);
    }

    /** A fatura tem de ser rastreável ao serviço. */
    public function test_a_fatura_referencia_o_servico(): void
    {
        $s = $this->servico();

        $fatura = $this->emitir($s);

        $this->assertSame($s->id, $fatura['reference']);
        $this->assertSame($s->created_at->format('d-m-Y'), $fatura['date']);
    }

    /** O id devolvido fica gravado: é o que trava a 2.ª emissão. */
    public function test_o_id_da_fatura_fica_gravado_no_servico(): void
    {
        $s = $this->servico();

        $this->emitir($s);

        $this->assertSame(self::FATURA_ID, (int) $s->fresh()->invoice_id);
    }

    // ---------------------------------------------------------------- B: os valores

    /** Sem extras: uma linha, e o bruto é o valor do serviço. */
    public function test_sem_extras_a_fatura_vale_o_servico(): void
    {
        $fatura = $this->emitir($this->servico());

        $this->assertCount(1, $fatura['items']);
        $this->assertSame(60.00, $this->brutoDaLinha($fatura['items'][0]));
    }

    /**
     * O CASO QUE ESTAVA ERRADO.
     *
     * 60 € de serviço + 50 € de peça + 15 € de tempo. O cliente pagou 125 €;
     * a fatura dizia 60 €. Agora tem de dizer 125 €, em três linhas.
     */
    public function test_a_peca_e_o_tempo_extra_entram_na_fatura(): void
    {
        $s = $this->servico();
        $this->extra($s, ['type' => 'part', 'description' => 'Torneira nova', 'amount' => 5000]);
        $this->extra($s, ['type' => 'time', 'minutes' => 30, 'amount' => 1500]);

        $fatura = $this->emitir($s->fresh());

        $this->assertCount(3, $fatura['items']);
        $this->assertSame(125.00, $this->totalBruto($fatura));

        $this->assertStringContainsString('Canalização', $fatura['items'][0]['description']);
        $this->assertSame('Peça/material: Torneira nova', $fatura['items'][1]['description']);
        $this->assertSame('Tempo extra: 30 min', $fatura['items'][2]['description']);

        $this->assertSame(50.00, $this->brutoDaLinha($fatura['items'][1]));
        $this->assertSame(15.00, $this->brutoDaLinha($fatura['items'][2]));
    }

    /**
     * Valores que não dividem bem por 1,23.
     *
     * 33,33 € + 16,67 € = 50,00 € exactos. Se o líquido fosse arredondado por
     * linha antes de somar, o total saía a 49,99 ou 50,01 -- um cêntimo de
     * diferença numa fatura fiscal é uma correção manual na AT.
     */
    public function test_valores_feios_nao_perdem_centimos(): void
    {
        $s = $this->servico(['amount' => 3333]);
        $this->extra($s, ['type' => 'part', 'description' => 'Peça', 'amount' => 1667]);

        $fatura = $this->emitir($s->fresh());

        $this->assertSame(50.00, $this->totalBruto($fatura));
    }

    /** Muitos extras pequenos: o total continua a ser a soma. */
    public function test_muitos_extras_somam_todos(): void
    {
        $s = $this->servico(['amount' => 1000]);
        for ($i = 1; $i <= 7; $i++) {
            $this->extra($s, ['type' => 'part', 'description' => 'Peça '.$i, 'amount' => 333]);
        }

        $fatura = $this->emitir($s->fresh());

        $this->assertCount(8, $fatura['items']);
        // 10,00 + 7 x 3,33 = 33,31
        $this->assertSame(33.31, $this->totalBruto($fatura));
    }

    // ---------------------------------------------------------------- C: o que NÃO entra

    /** @dataProvider extrasQueNaoSeFaturam */
    public function test_o_que_nao_foi_cobrado_nao_se_fatura(array $campos): void
    {
        $s = $this->servico();
        $this->extra($s, array_merge(['type' => 'part', 'description' => 'Peça', 'amount' => 5000], $campos));

        $fatura = $this->emitir($s->fresh());

        $this->assertCount(1, $fatura['items'], 'Entrou na fatura um extra que o cliente não pagou.');
        $this->assertSame(60.00, $this->totalBruto($fatura));
    }

    public static function extrasQueNaoSeFaturam(): array
    {
        return [
            'recusado pelo cliente' => [['status' => 'rejected', 'payment_status' => null]],
            'ainda por decidir' => [['status' => 'pending', 'payment_status' => null]],
            'aprovado mas cobrança falhou' => [['payment_status' => 'failed']],
            'aprovado e cobrança pendente' => [['payment_status' => 'pending']],
            'aprovado sem estado de pagamento' => [['payment_status' => null]],
            'valor zero' => [['amount' => 0]],
            'valor negativo' => [['amount' => -5000]],
        ];
    }

    /**
     * `not_required` ENTRA.
     *
     * É o mesmo predicado (`isCharged()`) que credita o técnico no fecho: um
     * extra dispensado de cobrança própria foi mesmo prestado e pago. Se o
     * crédito e a fatura divergissem aqui, o técnico recebia dinheiro que a
     * fatura não documentava.
     */
    public function test_um_extra_dispensado_de_cobranca_entra_na_fatura(): void
    {
        $s = $this->servico();
        $this->extra($s, ['type' => 'part', 'description' => 'Peça', 'amount' => 5000, 'payment_status' => 'not_required']);

        $fatura = $this->emitir($s->fresh());

        $this->assertCount(2, $fatura['items']);
        $this->assertSame(110.00, $this->totalBruto($fatura));
    }

    /**
     * O CRITÉRIO DA FATURA É O MESMO DO CRÉDITO AO TÉCNICO.
     *
     * Com todos os estados possíveis na mesma mesa, o conjunto faturado tem de
     * ser exactamente o conjunto que `isCharged()` aprova. Divergirem é
     * dinheiro a entrar sem documento, ou documento sem dinheiro.
     */
    public function test_fatura_e_credito_ao_tecnico_olham_para_o_mesmo_conjunto(): void
    {
        $s = $this->servico();
        $this->extra($s, ['type' => 'part', 'description' => 'Paga', 'amount' => 1000]);
        $this->extra($s, ['type' => 'part', 'description' => 'Dispensada', 'amount' => 2000, 'payment_status' => 'not_required']);
        $this->extra($s, ['type' => 'time', 'minutes' => 15, 'amount' => 3000, 'payment_status' => 'failed']);
        $this->extra($s, ['type' => 'time', 'minutes' => 15, 'amount' => 4000, 'status' => 'rejected', 'payment_status' => null]);

        $fatura = $this->emitir($s->fresh());

        $creditaveis = $s->fresh()->extras()->where('status', 'approved')->get()
            ->filter(fn ($e) => $e->isCharged() && $e->amount > 0);

        // 1 linha do serviço + uma por extra creditável
        $this->assertCount(1 + $creditaveis->count(), $fatura['items']);
        $this->assertSame(90.00, $this->totalBruto($fatura)); // 60 + 10 + 20
    }

    // ---------------------------------------------------------------- D: pedido personalizado

    /**
     * REGRESSÃO DE UM ERRO FATAL.
     *
     * Um pedido personalizado tem `services_type_id = null` de propósito. O
     * código fazia `$service->serviceType->getTranslation(...)`: Error fatal,
     * e o `CreateInvoiceJob` tem `tries = 1` -- a fatura nunca saía e o job
     * morria calado.
     */
    public function test_um_pedido_personalizado_e_faturado_com_a_descricao_do_cliente(): void
    {
        $s = $this->servico([
            'services_type_id' => null,
            'is_custom' => 1,
            'custom_description' => 'Montar um móvel de IKEA',
        ]);

        $fatura = $this->emitir($s);

        $this->assertSame('Serviço: Montar um móvel de IKEA', $fatura['items'][0]['description']);
    }

    /** Personalizado SEM descrição: não rebenta, e não emite linha em branco. */
    public function test_um_personalizado_sem_descricao_ainda_assim_fatura(): void
    {
        $s = $this->servico([
            'services_type_id' => null,
            'is_custom' => 1,
            'custom_description' => null,
        ]);

        $fatura = $this->emitir($s);

        $this->assertSame('Serviço: Serviço', $fatura['items'][0]['description']);
    }

    /** Um personalizado com extras leva as duas coisas certas de uma vez. */
    public function test_um_personalizado_com_extras(): void
    {
        $s = $this->servico([
            'services_type_id' => null, 'is_custom' => 1, 'custom_description' => 'Mudança de móveis',
        ]);
        $this->extra($s, ['type' => 'time', 'minutes' => 60, 'amount' => 2000]);

        $fatura = $this->emitir($s->fresh());

        $this->assertSame('Serviço: Mudança de móveis', $fatura['items'][0]['description']);
        $this->assertSame('Tempo extra: 60 min', $fatura['items'][1]['description']);
        $this->assertSame(80.00, $this->totalBruto($fatura));
    }

    // ---------------------------------------------------------------- E: o cliente na fatura

    /** O NIF do serviço manda sobre o do perfil: foi o que o cliente pediu na hora. */
    public function test_o_nif_do_servico_prevalece(): void
    {
        $s = $this->servico(['nif' => '111111111']);

        $fatura = $this->emitir($s);

        $this->assertSame('111111111', $fatura['client']['fiscal_id']);
    }

    /** Sem NIF nenhum sai consumidor final, não um campo vazio. */
    public function test_sem_nif_sai_consumidor_final(): void
    {
        $tecnico = $this->tecnico();
        $cliente = User::factory()->create(['nif' => null]);
        $s = $this->servico([], $tecnico);
        $s->forceFill(['customer_id' => $cliente->id, 'nif' => null])->save();

        $fatura = $this->emitir($s->fresh());

        $this->assertSame(999999990, $fatura['client']['fiscal_id']);
    }

    // ---------------------------------------------------------------- F: o job

    /** Idempotência: com fatura já emitida o job não fala com a InvoiceXpress. */
    public function test_o_job_nao_emite_duas_vezes(): void
    {
        $s = $this->servico();
        $s->forceFill(['invoice_id' => 123])->save();

        (new CreateInvoiceJob($s))->handle();

        Http::assertNothingSent();
    }

    /**
     * O JOB INTEIRO, com extras.
     *
     * O download do PDF usa a media library, que não passa pelo `Http::fake` --
     * por isso rebenta no fim com um URL inválido. Isso é posterior à emissão:
     * o que este teste prova é que o POST da fatura saiu com as três linhas.
     */
    public function test_o_job_emite_a_fatura_com_os_extras(): void
    {
        $s = $this->servico();
        $this->extra($s, ['type' => 'part', 'description' => 'Torneira', 'amount' => 5000]);
        $this->extra($s, ['type' => 'time', 'minutes' => 30, 'amount' => 1500]);

        try {
            (new CreateInvoiceJob($s->fresh()))->handle();
        } catch (\Throwable $e) {
            // Só o PDF. Se tivesse falhado antes, não havia POST para ler.
        }

        $corpo = null;
        Http::recorded(function ($p) use (&$corpo) {
            if (str_contains($p->url(), 'invoice_receipts.json')) {
                $corpo = $p->data();
            }
        });

        $this->assertNotNull($corpo);
        $this->assertCount(3, $corpo['invoice']['items']);
        $this->assertSame(125.00, $this->totalBruto($corpo['invoice']));
    }

    // ---------------------------------------------------------------- G: cancelamentos

    /** A fatura de cancelamento ao cliente está desligada de propósito. */
    public function test_a_fatura_de_cancelamento_ao_cliente_nao_emite_nada(): void
    {
        $s = $this->servico(['status' => ServiceStatus::CANCELED]);

        (new CreateCancellationInvoiceJob($s))->handle();

        Http::assertNothingSent();
    }

    /**
     * A FATURA DE CANCELAMENTO AO TÉCNICO AINDA EMITE -- e cobra 10%.
     *
     * Este teste documenta o estado actual, não o aprova: o `CancelService`
     * tem `$cancellationFee = 0; // Temporary remove cancellation fee` e NÃO
     * move dinheiro nenhum, mas continua a despachar este job, que emite um
     * documento fiscal de 10% do valor do técnico. Se a taxa voltar a ser
     * cobrada, este teste tem de passar a comparar os dois valores.
     */
    public function test_a_fatura_de_cancelamento_ao_tecnico_cobra_10_por_cento_do_valor_do_tecnico(): void
    {
        $s = $this->servico(['status' => ServiceStatus::CANCELED, 'amount_for_vendor' => 4500]);

        try {
            (new CreateVendorCancellationInvoiceJob($s))->handle();
        } catch (\Throwable $e) {
            // Rebenta no download do PDF (media library, fora do Http::fake).
            // É depois da emissão -- o POST abaixo é que interessa.
        }

        $corpo = null;
        Http::recorded(function ($p) use (&$corpo) {
            if (str_contains($p->url(), 'invoice_receipts.json')) {
                $corpo = $p->data();
            }
        });

        $this->assertNotNull($corpo, 'O job do cancelamento do técnico não emitiu nada.');
        // 10% de 45,00 € = 4,50 €
        $this->assertSame(4.50, $this->brutoDaLinha($corpo['invoice']['items'][0]));
    }
}
