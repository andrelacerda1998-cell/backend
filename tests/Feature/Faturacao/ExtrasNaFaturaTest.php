<?php

namespace Tests\Feature\Faturacao;

use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\Gender;
use App\Models\Service;
use App\Models\Vendor;
use App\Services\InvoiceXpress\InvoiceVendorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * As peças e o tempo extra que o cliente pagou vão na fatura.
 *
 * A fatura levava uma linha só, com `$service->amount`, onde os extras não
 * entram. Mas o extra é cobrado ao cliente quando ele o aprova, e o técnico é
 * creditado por ele no fecho: num serviço de 60 € com uma peça de 50 € e meia
 * hora extra de 15 €, o cliente pagava 125 € e a fatura dizia 60 €.
 *
 * O InvoiceXpress está falso (Http::fake): mede-se o que se lhe envia.
 */
class ExtrasNaFaturaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['scout.driver' => 'null']);
        Gender::firstOrCreate(['name' => 'Masculino']);
        Notification::fake();
        Queue::fake();
        Http::fake(['*' => Http::response(['invoice_receipt' => ['id' => 4242]], 200)]);
    }

    /** Um serviço fechado de 60 €. */
    private function servico(): Service
    {
        $vendor = Vendor::factory()->create(['invoice_workspace' => 'oficina-teste', 'auth_token' => 'chave-de-teste']);

        return Service::factory()->create([
            'vendor_id' => $vendor->id,
            'status' => ServiceStatus::CLOSED,
            'amount' => 6000,
            'amount_for_vendor' => 4500,
        ]);
    }

    private function extra(Service $servico, array $atributos): void
    {
        $servico->extras()->create([
            'status' => 'approved',
            'payment_status' => 'paid',
            'charged_at' => now(),
            ...$atributos,
        ]);
    }

    private function peca(Service $servico, array $atributos = []): void
    {
        $this->extra($servico, ['type' => 'part', 'description' => 'Torneira nova', 'amount' => 5000, ...$atributos]);
    }

    private function tempo(Service $servico, array $atributos = []): void
    {
        $this->extra($servico, ['type' => 'time', 'minutes' => 30, 'amount' => 1500, ...$atributos]);
    }

    /** As linhas que seguiram no último pedido ao InvoiceXpress. */
    private function linhasEnviadas(Service $servico, ?Carbon $emitidaEm = null): array
    {
        (new InvoiceVendorService($servico->vendor))->createServiceInvoice($servico->fresh(), $emitidaEm);

        /** @var Request $pedido */
        $pedido = Http::recorded()->last()[0];

        return $pedido['invoice']['items'];
    }

    /** O total da fatura com IVA: `unit_price` vai sem ele. */
    private function totalComIva(array $linhas): float
    {
        $semIva = array_sum(array_map(fn (array $l) => $l['unit_price'] * $l['quantity'], $linhas));

        return round($semIva * (1 + config('services.invoiceExpress.vat') / 100), 2);
    }

    public function test_sem_extras_a_fatura_tem_a_linha_do_servico(): void
    {
        $linhas = $this->linhasEnviadas($this->servico());

        $this->assertCount(1, $linhas);
        $this->assertStringStartsWith('Serviço: ', $linhas[0]['description']);
        $this->assertSame(60.0, $this->totalComIva($linhas));
    }

    /** O caso que estava errado: o cliente pagou 125 € e a fatura dizia 60 €. */
    public function test_a_peca_e_o_tempo_extra_entram_e_o_total_e_o_que_o_cliente_pagou(): void
    {
        $servico = $this->servico();
        $this->peca($servico);
        $this->tempo($servico);

        $linhas = $this->linhasEnviadas($servico);

        $this->assertSame(
            ['Peça/material: Torneira nova', 'Tempo extra: 30 min'],
            array_column(array_slice($linhas, 1), 'description'),
        );
        $this->assertSame(125.0, $this->totalComIva($linhas));
    }

    /**
     * Só entra o que o técnico recebe (CloseService::settleExtras): o que o
     * cliente recusou, o que ainda não aprovou, o que falhou a cobrança, o
     * MBWay que ficou por confirmar, o de 0 € e o apagado ficam de fora.
     */
    public function test_o_que_nao_foi_cobrado_nao_entra(): void
    {
        $servico = $this->servico();
        $this->peca($servico, ['status' => 'rejected', 'payment_status' => null, 'charged_at' => null]);
        $this->peca($servico, ['status' => 'pending', 'payment_status' => null, 'charged_at' => null]);
        $this->peca($servico, ['payment_status' => 'failed', 'charged_at' => null]);
        $this->peca($servico, ['payment_status' => 'pending_confirmation', 'charged_at' => null]);
        $this->tempo($servico, ['amount' => 0, 'payment_status' => 'not_required']);
        $this->peca($servico);
        $servico->extras()->latest('id')->first()->delete();

        $linhas = $this->linhasEnviadas($servico);

        $this->assertCount(1, $linhas);
        $this->assertSame(60.0, $this->totalComIva($linhas));
    }

    /** A fatura em atraso (FaturasEmAtraso) passa pelo mesmo sítio e leva os extras. */
    public function test_a_fatura_em_atraso_tambem_leva_os_extras(): void
    {
        $servico = $this->servico();
        $this->peca($servico);

        $linhas = $this->linhasEnviadas($servico, now());

        $this->assertStringContainsString('(realizado a ', $linhas[0]['description']);
        $this->assertSame('Peça/material: Torneira nova', $linhas[1]['description']);
        $this->assertSame(110.0, $this->totalComIva($linhas));
    }
}
