<?php

namespace Tests\Feature\Faturacao;

use App\Enums\Services\ServiceStatus;
use App\Jobs\Services\EmitirFaturaEmAtrasoJob;
use App\Models\Service;
use App\Services\InvoiceXpress\FaturasEmAtraso;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Support\FakeInvoiceVendorService;
use Tests\Support\TecnicosParaFaturar;
use Tests\TestCase;

/**
 * As faturas que ficaram por emitir quando o serviço fechou (técnico sem conta
 * de faturação ou sem AT) saem quando ele passa a ter tudo. Decisão do André,
 * 13/10/2026 ("solução 1").
 *
 * O InvoiceXpress é um duplo em memória (FakeInvoiceVendorService): o que se
 * mede aqui é a ordem das chamadas e o que fica guardado, nunca HTTP.
 */
class FaturasEmAtrasoTest extends TestCase
{
    use DatabaseTruncation, TecnicosParaFaturar;

    private FakeInvoiceVendorService $invoiceXpress;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararFaturacao();
        $this->invoiceXpress = FakeInvoiceVendorService::ligar();
    }

    private function emitir(Service $servico): string
    {
        return app(FaturasEmAtraso::class)->emitir($servico);
    }

    // ------------------------------------------------------------ emitir

    public function test_um_servico_sem_fatura_ganha_uma_com_a_data_de_hoje(): void
    {
        $servico = $this->servicoFechado($this->tecnico());

        $this->assertSame('emitida', $this->emitir($servico));

        $this->assertSame(['criar', 'finalizar', 'pdf'], $this->invoiceXpress->passos());
        $servico->refresh();
        $this->assertTrue($servico->temFaturaEmitida(), 'o PDF tem de ficar guardado no serviço');
        $this->assertSame(
            now()->toDateString(),
            $this->invoiceXpress->datas[$servico->invoice_id],
            'a fatura em atraso sai com a data do dia em que é emitida'
        );
    }

    /**
     * Um rascunho finaliza-se. Criar outra fatura deixava duas para o mesmo
     * serviço no InvoiceXpress -- a pior coisa que isto podia fazer.
     */
    public function test_um_rascunho_e_finalizado_e_nao_substituido(): void
    {
        $servico = $this->servicoFechado($this->tecnico(), atributos: ['invoice_id' => 500]);
        $this->invoiceXpress->jaExiste(500, 'draft');

        $this->assertSame('emitida', $this->emitir($servico));

        $this->assertSame([['estado', 500], ['finalizar', 500], ['pdf', 500]], $this->invoiceXpress->chamadas);
        $this->assertSame(500, (int) $servico->fresh()->invoice_id);
    }

    /** Finalizada numa tentativa que não chegou a guardar o PDF: só falta o PDF. */
    public function test_uma_fatura_ja_finalizada_sem_pdf_so_vai_buscar_o_pdf(): void
    {
        $servico = $this->servicoFechado($this->tecnico(), atributos: ['invoice_id' => 501]);
        $this->invoiceXpress->jaExiste(501, 'settled');

        $this->assertSame('emitida', $this->emitir($servico));

        $this->assertSame([['estado', 501], ['pdf', 501]], $this->invoiceXpress->chamadas);
        $this->assertTrue($servico->fresh()->temFaturaEmitida());
    }

    /** Anulado à mão no InvoiceXpress: nem se finaliza nem se cria outra. Fica para ver. */
    public function test_um_documento_anulado_fica_para_ver_a_mao(): void
    {
        $servico = $this->servicoFechado($this->tecnico(), atributos: ['invoice_id' => 502]);
        $this->invoiceXpress->jaExiste(502, 'canceled');

        $this->assertSame('falhou', $this->emitir($servico));

        $this->assertSame([['estado', 502]], $this->invoiceXpress->chamadas);
        $this->assertFalse($servico->fresh()->temFaturaEmitida());
    }

    public function test_sem_serie_comunicada_cria_a_serie_e_finaliza_outra_vez(): void
    {
        $servico = $this->servicoFechado($this->tecnico());
        $this->invoiceXpress->respostasAoFinalizar = [
            ['errors' => [['error' => 'Este documento não tem ATCUD. Comunique a série à AT.']]],
        ];

        $this->assertSame('emitida', $this->emitir($servico));

        $this->assertSame(['criar', 'finalizar', 'serie', 'finalizar', 'pdf'], $this->invoiceXpress->passos());
    }

    /**
     * Recusada: não há PDF, e o rascunho fica com o `invoice_id` no serviço --
     * a tentativa seguinte finaliza-o em vez de criar outro.
     */
    public function test_se_o_invoicexpress_recusa_finalizar_nao_ha_pdf(): void
    {
        $servico = $this->servicoFechado($this->tecnico());
        $this->invoiceXpress->respostasAoFinalizar = [['errors' => [['error' => 'Data inválida']]]];

        $this->assertSame('falhou', $this->emitir($servico));

        $servico->refresh();
        $this->assertFalse($servico->temFaturaEmitida());
        $this->assertNotNull($servico->invoice_id, 'o rascunho fica ligado ao serviço');

        $this->invoiceXpress->chamadas = [];
        $this->assertSame('emitida', $this->emitir($servico));
        $this->assertSame(['estado', 'finalizar', 'pdf'], $this->invoiceXpress->passos(), 'à segunda, finaliza o mesmo');
    }

    public function test_credenciais_da_at_recusadas_param_e_desligam_a_at(): void
    {
        $vendor = $this->tecnico();
        $servico = $this->servicoFechado($vendor);
        $this->invoiceXpress->respostasAoFinalizar = [['errors' => [['error' => 'Este documento não tem ATCUD.']]]];
        $this->invoiceXpress->credenciaisDaAtRecusadas = true;

        $this->assertSame('falhou', $this->emitir($servico));

        $this->assertFalse((bool) $vendor->fresh()->at_valid, 'o técnico tem de voltar a dar o acesso');
        $this->assertNotContains('pdf', $this->invoiceXpress->passos());
    }

    public function test_sem_conta_de_faturacao_nao_toca_no_invoicexpress(): void
    {
        $servico = $this->servicoFechado($this->tecnico(['invoice_workspace' => '']));

        $this->assertSame('sem_dados', $this->emitir($servico));
        $this->assertSame([], $this->invoiceXpress->chamadas);
    }

    public function test_sem_at_nao_toca_no_invoicexpress(): void
    {
        $servico = $this->servicoFechado($this->tecnicoSemAt());

        $this->assertSame('sem_dados', $this->emitir($servico));
        $this->assertSame([], $this->invoiceXpress->chamadas);
    }

    public function test_um_servico_com_fatura_nao_e_faturado_outra_vez(): void
    {
        $servico = $this->comFatura($this->servicoFechado($this->tecnico()));

        $this->assertSame('ja_emitida', $this->emitir($servico));
        $this->assertSame([], $this->invoiceXpress->chamadas);
    }

    /** A tarefa diária e o "emitir agora" ao mesmo tempo: só um avança. */
    public function test_dois_ao_mesmo_tempo_nao_emitem_duas_faturas(): void
    {
        $servico = $this->servicoFechado($this->tecnico());
        $outraTentativa = Cache::lock('fatura-em-atraso:'.$servico->id, 600);
        $this->assertTrue($outraTentativa->get());

        $this->assertSame('ocupada', $this->emitir($servico));
        $this->assertSame([], $this->invoiceXpress->chamadas);

        $outraTentativa->release();
    }

    // ------------------------------------------------- quais entram

    public function test_so_entram_os_fechados_desde_30_09_fora_de_teste_e_sem_pdf(): void
    {
        $vendor = $this->tecnico();

        $entra = $this->servicoFechado($vendor);
        $this->servicoFechado($vendor, atributos: ['is_test' => true]);
        $this->servicoFechado($vendor, atributos: ['status' => ServiceStatus::FINISHED]);
        $this->comFatura($this->servicoFechado($vendor));

        $antigo = $this->servicoFechado($vendor);
        $antigo->forceFill(['created_at' => '2026-09-29 23:59:59'])->saveQuietly();

        $this->assertSame([$entra->id], Service::query()->porFaturar()->pluck('id')->all());
    }

    // ----------------------------------------- a tarefa diária e a fila

    public function test_a_tarefa_diaria_poe_em_fila_os_de_quem_ja_pode_faturar(): void
    {
        Bus::fake();

        $pronto = $this->tecnico();
        $primeiro = $this->servicoFechado($pronto);
        $segundo = $this->servicoFechado($pronto);
        $this->servicoFechado($this->tecnicoSemAt());

        $this->artisan('faturas:emitir-em-atraso')->assertSuccessful();

        // Em cadeia e do mais antigo para o mais recente: uma de cada vez,
        // pela ordem em que o InvoiceXpress as numera.
        Bus::assertChained([
            fn (EmitirFaturaEmAtrasoJob $tarefa) => $tarefa->service->is($primeiro),
            fn (EmitirFaturaEmAtrasoJob $tarefa) => $tarefa->service->is($segundo),
        ]);
        Bus::assertDispatchedTimes(EmitirFaturaEmAtrasoJob::class, 1);
    }

    public function test_dry_run_nao_poe_nada_em_fila(): void
    {
        Bus::fake();
        $this->servicoFechado($this->tecnico());

        $this->artisan('faturas:emitir-em-atraso', ['--dry-run' => true])->assertSuccessful();

        Bus::assertNotDispatched(EmitirFaturaEmAtrasoJob::class);
    }

    /** A tarefa emite mesmo: corrida à mão (a fila está falsa), o PDF fica guardado. */
    public function test_a_tarefa_de_um_servico_emite_a_fatura(): void
    {
        $servico = $this->servicoFechado($this->tecnico());

        app()->call([new EmitirFaturaEmAtrasoJob($servico), 'handle']);

        $this->assertTrue($servico->fresh()->temFaturaEmitida());
    }

    // --------------------------------------------------------- backoffice

    public function test_o_backoffice_ve_o_que_falta_a_cada_fatura(): void
    {
        $pronta = $this->servicoFechado($this->tecnico());
        $iniciada = $this->servicoFechado($this->tecnico(), atributos: ['invoice_id' => 777]);
        $semAt = $this->servicoFechado($this->tecnicoSemAt());
        $semConta = $this->servicoFechado($this->tecnico(['invoice_workspace' => '']));
        $this->comFatura($this->servicoFechado($this->tecnico()));

        $resposta = $this->comAuthDeAdmin()
            ->getJson('/api/v1/admin/faturas-por-emitir')
            ->assertOk()
            ->assertJsonPath('data.total', 4);

        $situacoes = collect($resposta->json('data.items'))->pluck('situacao', 'service_id')->all();

        $this->assertSame([
            $pronta->id => 'pronta_a_emitir',
            $iniciada->id => 'fatura_iniciada',
            $semAt->id => 'sem_at',
            $semConta->id => 'sem_conta_de_faturacao',
        ], $situacoes);
    }

    public function test_emitir_agora_poe_as_faturas_do_tecnico_em_fila(): void
    {
        Bus::fake();
        $vendor = $this->tecnico();
        $this->servicoFechado($vendor);
        $this->servicoFechado($vendor);

        $this->comAuthDeAdmin()
            ->postJson("/api/v1/admin/faturas-por-emitir/{$vendor->id}/emitir")
            ->assertOk()
            ->assertJsonPath('data.por_emitir', 2);

        Bus::assertDispatchedTimes(EmitirFaturaEmAtrasoJob::class, 1);
    }

    public function test_emitir_agora_sem_at_explica_o_que_falta(): void
    {
        Bus::fake();
        $vendor = $this->tecnicoSemAt();
        $this->servicoFechado($vendor);

        $this->comAuthDeAdmin()
            ->postJson("/api/v1/admin/faturas-por-emitir/{$vendor->id}/emitir")
            ->assertStatus(409);

        Bus::assertNotDispatched(EmitirFaturaEmAtrasoJob::class);
    }

    public function test_emitir_agora_sem_nada_por_emitir_responde_409(): void
    {
        Bus::fake();
        $vendor = $this->tecnico();
        $this->comFatura($this->servicoFechado($vendor));

        $this->comAuthDeAdmin()
            ->postJson("/api/v1/admin/faturas-por-emitir/{$vendor->id}/emitir")
            ->assertStatus(409);

        Bus::assertNotDispatched(EmitirFaturaEmAtrasoJob::class);
    }

    public function test_a_lista_pede_autenticacao(): void
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        $this->getJson('/api/v1/admin/faturas-por-emitir')->assertUnauthorized();
    }
}
