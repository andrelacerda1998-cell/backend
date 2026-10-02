<?php

namespace Tests\Feature\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\GeneralSettings\Gender;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Services\InvoiceXpress\InvoiceVendorService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * O QUE SAI NA FATURA QUANDO HÁ PEÇAS OU TEMPO EXTRA.
 *
 * A fatura levava UMA linha, com `$service->amount` -- e nada soma os extras a
 * esse valor. Como o extra é cobrado ao cliente NA APROVAÇÃO, um serviço de
 * 60 € com uma peça de 50 € e meia hora extra de 15 € cobrava 125 € e faturava
 * 60 €: 65 € recebidos sem documento fiscal, sob o NIF do técnico.
 *
 * IVA 23% em tudo (decisão do André, 02/10/2026) -- é a taxa do documento
 * inteiro, por isso não há conta à parte por linha.
 */
class FaturaComExtrasTest extends TestCase
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

    private function servico(array $extra = []): Service
    {
        $user = User::factory()->create();
        $v = Vendor::create(['user_id' => $user->id, 'username' => 'tec_'.$user->id]);
        // Credenciais falsas: o construtor do servico exige-as como string. Nada
        // sai para a InvoiceXpress neste teste -- so se leem as linhas montadas.
        $v->forceFill(['auth_token' => 'token-de-teste', 'invoice_workspace' => 'ws-de-teste'])->save();
        $cliente = User::factory()->create();

        $s = new Service();
        $s->forceFill(array_merge([
            'customer_id' => $cliente->id,
            'vendor_id' => $v->id,
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
        ], $extra))->save();

        return $s->fresh();
    }

    /** As linhas são privadas: lê-se por reflexão, sem tocar na InvoiceXpress. */
    private function linhas(Service $s): array
    {
        $servico = new InvoiceVendorService($s->vendor);
        $metodo = new \ReflectionMethod($servico, 'linhasDaFatura');
        $metodo->setAccessible(true);

        return $metodo->invoke($servico, $s, (int) $s->amount);
    }

    /** Sem extras, a fatura é o que sempre foi: uma linha. */
    public function test_sem_extras_a_fatura_tem_uma_linha(): void
    {
        $linhas = $this->linhas($this->servico());

        $this->assertCount(1, $linhas);
        $this->assertStringStartsWith('Serviço: ', $linhas[0]['description']);
    }

    /**
     * O caso que estava errado: 60 € de serviço, 50 € de peça, 15 € de tempo.
     * O cliente pagou 125 €; a fatura dizia 60 €.
     */
    public function test_a_peca_e_o_tempo_extra_aparecem_como_linhas_proprias(): void
    {
        $s = $this->servico();
        $s->extras()->create([
            'type' => 'part', 'description' => 'Torneira nova', 'amount' => 5000,
            'status' => 'approved', 'payment_status' => 'paid', 'charged_at' => now(),
        ]);
        $s->extras()->create([
            'type' => 'time', 'minutes' => 30, 'amount' => 1500,
            'status' => 'approved', 'payment_status' => 'paid', 'charged_at' => now(),
        ]);

        $linhas = $this->linhas($s->fresh());

        $this->assertCount(3, $linhas);
        $this->assertStringContainsString('Peça/material: Torneira nova', $linhas[1]['description']);
        $this->assertStringContainsString('Tempo extra: 30 min', $linhas[2]['description']);
    }

    /**
     * O TOTAL DA FATURA PASSA A SER O QUE O CLIENTE PAGOU.
     *
     * `unit_price` é líquido (o bruto a dividir por 1,23); somando e voltando a
     * aplicar o IVA tem de dar os 125 €.
     */
    public function test_o_total_bate_certo_com_o_que_o_cliente_pagou(): void
    {
        $s = $this->servico();
        $s->extras()->create([
            'type' => 'part', 'description' => 'Torneira', 'amount' => 5000,
            'status' => 'approved', 'payment_status' => 'paid', 'charged_at' => now(),
        ]);
        $s->extras()->create([
            'type' => 'time', 'minutes' => 30, 'amount' => 1500,
            'status' => 'approved', 'payment_status' => 'paid', 'charged_at' => now(),
        ]);

        $liquido = array_sum(array_column($this->linhas($s->fresh()), 'unit_price'));
        $bruto = $liquido * (1 + config('services.invoiceExpress.vat') / 100);

        // 60,00 + 50,00 + 15,00 = 125,00 €
        $this->assertEqualsWithDelta(125.00, $bruto, 0.01);
    }

    /** O que o cliente recusou não se fatura. */
    public function test_um_extra_recusado_nao_entra_na_fatura(): void
    {
        $s = $this->servico();
        $s->extras()->create([
            'type' => 'part', 'description' => 'Peça', 'amount' => 5000,
            'status' => 'rejected', 'payment_status' => null,
        ]);

        $this->assertCount(1, $this->linhas($s->fresh()));
    }

    /** O que nunca foi cobrado também não. */
    public function test_um_extra_que_falhou_a_cobranca_nao_entra_na_fatura(): void
    {
        $s = $this->servico();
        $s->extras()->create([
            'type' => 'part', 'description' => 'Peça', 'amount' => 5000,
            'status' => 'approved', 'payment_status' => 'failed',
        ]);

        $this->assertCount(1, $this->linhas($s->fresh()));
    }

    /**
     * Um pedido PERSONALIZADO não tem tipo de serviço: isto rebentava com
     * `Error` fatal e a fatura nunca saía (`tries = 1`).
     */
    public function test_um_pedido_personalizado_usa_a_descricao_do_cliente(): void
    {
        $s = $this->servico([
            'services_type_id' => null,
            'is_custom' => 1,
            'custom_description' => 'Montar um móvel de IKEA',
        ]);

        $linhas = $this->linhas($s);

        $this->assertSame('Serviço: Montar um móvel de IKEA', $linhas[0]['description']);
    }
}
