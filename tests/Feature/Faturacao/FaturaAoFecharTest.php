<?php

namespace Tests\Feature\Faturacao;

use App\Jobs\Services\CreateInvoiceJob;
use App\Models\Service;
use App\Services\InvoiceXpress\FaturasEmAtraso;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\FakeInvoiceVendorService;
use Tests\Support\TecnicosParaFaturar;
use Tests\TestCase;

/**
 * A fatura que se tenta ao fechar o serviço (CreateInvoiceJob).
 *
 * O InvoiceXpress gera PDF também de um RASCUNHO (visto a 13/10/2026 na conta
 * de testes). Até aqui, quando a série ainda não estava comunicada à AT, o job
 * criava a série e ia direto ao PDF, sem voltar a finalizar: guardava o PDF do
 * rascunho, e o serviço passava por faturado com a fatura por finalizar.
 */
class FaturaAoFecharTest extends TestCase
{
    use DatabaseTruncation, TecnicosParaFaturar;

    private const SEM_ATCUD = ['errors' => [['error' => 'Este documento não tem ATCUD. Comunique a série à AT.']]];

    private FakeInvoiceVendorService $invoiceXpress;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararFaturacao();
        $this->invoiceXpress = FakeInvoiceVendorService::ligar();
    }

    private function faturarAoFechar(Service $servico): void
    {
        app()->call([new CreateInvoiceJob($servico), 'handle']);
    }

    public function test_finalizada_guarda_o_pdf(): void
    {
        $servico = $this->servicoFechado($this->tecnico());

        $this->faturarAoFechar($servico);

        $this->assertSame(['criar', 'finalizar', 'pdf'], $this->invoiceXpress->passos());
        $this->assertTrue($servico->fresh()->temFaturaEmitida());
    }

    public function test_sem_serie_cria_a_serie_e_finaliza_outra_vez_antes_do_pdf(): void
    {
        $servico = $this->servicoFechado($this->tecnico());
        $this->invoiceXpress->respostasAoFinalizar = [self::SEM_ATCUD];

        $this->faturarAoFechar($servico);

        $this->assertSame(['criar', 'finalizar', 'serie', 'finalizar', 'pdf'], $this->invoiceXpress->passos());
        $this->assertSame('settled', $this->invoiceXpress->estados[$servico->fresh()->invoice_id]);
    }

    /** O caso que guardava o PDF do rascunho: a série criada, e a fatura ainda por finalizar. */
    public function test_se_continua_por_finalizar_nao_guarda_o_pdf_do_rascunho(): void
    {
        $servico = $this->servicoFechado($this->tecnico());
        $this->invoiceXpress->respostasAoFinalizar = [self::SEM_ATCUD, ['errors' => [['error' => 'Data inválida']]]];

        $this->faturarAoFechar($servico);

        $servico->refresh();
        $this->assertNotContains('pdf', $this->invoiceXpress->passos());
        $this->assertFalse($servico->temFaturaEmitida(), 'um rascunho não é uma fatura');
        $this->assertNotNull($servico->invoice_id, 'o rascunho fica ligado ao serviço');
        $this->assertSame([$servico->id], Service::query()->porFaturar()->pluck('id')->all(), 'e fica na lista das por emitir');
    }

    public function test_outro_erro_ao_finalizar_nao_guarda_o_pdf(): void
    {
        $servico = $this->servicoFechado($this->tecnico());
        $this->invoiceXpress->respostasAoFinalizar = [['errors' => [['error' => 'Data inválida']]]];

        $this->faturarAoFechar($servico);

        $this->assertSame(['criar', 'finalizar'], $this->invoiceXpress->passos());
        $this->assertFalse($servico->fresh()->temFaturaEmitida());
    }

    /** Credenciais recusadas: a série não se cria, e a fatura continua sem ATCUD. */
    public function test_credenciais_da_at_recusadas_nao_guardam_o_pdf(): void
    {
        $vendor = $this->tecnico();
        $servico = $this->servicoFechado($vendor);
        $this->invoiceXpress->respostasAoFinalizar = [self::SEM_ATCUD, self::SEM_ATCUD];
        $this->invoiceXpress->credenciaisDaAtRecusadas = true;

        $this->faturarAoFechar($servico);

        $this->assertNotContains('pdf', $this->invoiceXpress->passos());
        $this->assertFalse($servico->fresh()->temFaturaEmitida());
        $this->assertFalse((bool) $vendor->fresh()->at_valid);
    }

    /** O rascunho que fica aqui é o que a tarefa diária finaliza depois. */
    public function test_o_rascunho_que_fica_e_finalizado_pela_tarefa_diaria(): void
    {
        $servico = $this->servicoFechado($this->tecnico());
        $this->invoiceXpress->respostasAoFinalizar = [['errors' => [['error' => 'Data inválida']]]];
        $this->faturarAoFechar($servico);

        $this->invoiceXpress->chamadas = [];
        $this->assertSame('emitida', app(FaturasEmAtraso::class)->emitir($servico));

        $this->assertSame(['estado', 'finalizar', 'pdf'], $this->invoiceXpress->passos());
        $this->assertTrue($servico->fresh()->temFaturaEmitida());
    }
}
