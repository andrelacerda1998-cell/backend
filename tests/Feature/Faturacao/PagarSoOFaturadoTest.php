<?php

namespace Tests\Feature\Faturacao;

use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\TecnicosParaFaturar;
use Tests\TestCase;

/**
 * Só se paga um serviço depois de a fatura dele sair (decisão do André,
 * 13/10/2026). O resto do saldo -- serviços já faturados, crédito promocional
 * -- paga-se como sempre.
 */
class PagarSoOFaturadoTest extends TestCase
{
    use DatabaseTruncation, TecnicosParaFaturar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->prepararFaturacao();
    }

    private function pagar(Vendor $vendor, ?float $valor = null)
    {
        return $this->comAuthDeAdmin()->putJson(
            "/api/v1/admin/vendor-payments/{$vendor->id}/pay",
            $valor === null ? [] : ['amount' => $valor]
        );
    }

    private function saldo(Vendor $vendor): int
    {
        return $vendor->user->wallet->refresh()->balanceInt;
    }

    // ------------------------------------------------------------- a conta

    public function test_so_o_dinheiro_de_servicos_por_faturar_fica_a_espera(): void
    {
        $vendor = $this->tecnico();
        $this->servicoFechado($vendor, 7500);
        $this->comFatura($this->servicoFechado($vendor, 3000));
        $this->creditoPromocional($vendor, 2000);

        $vendor = $vendor->fresh();

        $this->assertSame(7500, $vendor->ganhos_por_faturar);
        $this->assertSame(7500, $vendor->ganhos_retidos_pela_fatura);
        $this->assertSame(5000, $vendor->ganhos_pagaveis, 'o faturado e o promocional pagam-se');
        $this->assertNull($vendor->payoutBlocker(), 'há o que pagar: o pagamento não está travado');
        $this->assertSame('invoice_pending', $vendor->payoutHoldReason());
        $this->assertSame(7500, $vendor->payout_on_hold_amount);
    }

    public function test_o_pagamento_leva_so_o_que_se_pode_pagar_e_fica_marcado(): void
    {
        $vendor = $this->tecnico();
        $this->servicoFechado($vendor, 7500);
        $this->creditoPromocional($vendor, 2000);

        $this->pagar($vendor)->assertOk()->assertJsonPath('data.amount_paid', 20);

        $this->assertSame(7500, $this->saldo($vendor), 'o serviço por faturar fica na carteira');

        $pagamento = $vendor->user->wallet->transactions()->where('type', 'withdraw')->latest('id')->first();
        $this->assertTrue($pagamento->meta[Vendor::PAGAMENTO_SO_DO_FATURADO] ?? false);
    }

    /**
     * A fuga que a primeira versão desta regra tinha: os `ganhos_por_pagar`
     * contam qualquer pagamento como pagamento de serviços, e pagar o crédito
     * promocional abria a porta, no pagamento seguinte, a 20 € do serviço por
     * faturar. Medido com o saldo, não com os ganhos por pagar.
     */
    public function test_pagar_o_promocional_nao_abre_a_porta_ao_dinheiro_por_faturar(): void
    {
        $vendor = $this->tecnico();
        $this->servicoFechado($vendor, 7500);
        $this->creditoPromocional($vendor, 2000);

        $this->pagar($vendor)->assertOk();
        $this->pagar($vendor->fresh())->assertStatus(409);

        $this->assertSame(7500, $this->saldo($vendor));
        $this->assertSame('invoice_pending', $vendor->fresh()->payoutBlocker());
    }

    public function test_depois_de_a_fatura_sair_paga_se(): void
    {
        $vendor = $this->tecnico();
        $servico = $this->servicoFechado($vendor, 7500);

        $this->pagar($vendor)->assertStatus(409);

        $this->comFatura($servico);

        $this->pagar($vendor->fresh())->assertOk()->assertJsonPath('data.amount_paid', 75);
        $this->assertSame(0, $this->saldo($vendor));
    }

    /**
     * Antes desta regra pagava-se o saldo todo. Um serviço por faturar pago
     * nessa altura já saiu: não pode prender o dinheiro que entrou depois.
     */
    public function test_um_servico_pago_antes_da_regra_nao_prende_nada(): void
    {
        $vendor = $this->tecnico();
        $this->servicoFechado($vendor, 7500);
        $this->pagamentoDeAntesDaRegra($vendor, 7500);
        $this->comFatura($this->servicoFechado($vendor, 3000));

        $vendor = $vendor->fresh();

        $this->assertSame(0, $vendor->ganhos_por_faturar);
        $this->assertSame(3000, $vendor->ganhos_pagaveis);
    }

    /** Depois da regra, um pagamento nunca leva dinheiro por faturar: não "paga" o serviço. */
    public function test_um_pagamento_com_a_regra_nao_conta_como_pagamento_do_servico(): void
    {
        $vendor = $this->tecnico();
        $this->servicoFechado($vendor, 7500);
        $this->creditoPromocional($vendor, 2000);
        $this->pagar($vendor)->assertOk();

        $this->assertSame(7500, $vendor->fresh()->ganhos_por_faturar);
    }

    // ----------------------------------------------------- o motivo

    /**
     * Sem AT, a fatura não sai, e por isso o dinheiro também não -- mesmo
     * antes do terceiro serviço, quando a AT ainda não trava o trabalho.
     */
    public function test_sem_at_e_tudo_por_faturar_o_motivo_e_a_at(): void
    {
        $vendor = $this->tecnicoSemAt(['invoice_workspace' => '']);
        $this->servicoFechado($vendor, 7500);

        $vendor = $vendor->fresh();

        $this->assertFalse($vendor->at_required, 'é o primeiro serviço');
        $this->assertSame('at_user_missing', $vendor->payoutBlocker());

        $this->pagar($vendor)->assertStatus(409);
        $this->assertSame(7500, $this->saldo($vendor));
    }

    public function test_com_a_at_dada_e_a_fatura_por_emitir_o_motivo_e_da_piquet(): void
    {
        $vendor = $this->tecnico();
        $this->servicoFechado($vendor, 7500);

        $this->assertSame('invoice_pending', $vendor->fresh()->payoutBlocker());
    }

    /** O que já travava continua a vir primeiro: sem IBAN nem se chega à fatura. */
    public function test_sem_iban_o_motivo_continua_a_ser_o_iban(): void
    {
        $vendor = $this->tecnicoSemAt(['iban' => null]);
        $this->servicoFechado($vendor, 7500);

        $this->assertSame('iban_missing', $vendor->fresh()->payoutBlocker());
    }

    // ------------------------------------------------- o backoffice

    public function test_pagar_mais_do_que_o_faturado_e_recusado_com_o_maximo(): void
    {
        $vendor = $this->tecnico();
        $this->servicoFechado($vendor, 7500);
        $this->creditoPromocional($vendor, 2000);

        $this->pagar($vendor, 95)
            ->assertStatus(409)
            ->assertJsonFragment(['message' => 'O valor a pagar (95.00 €) inclui serviços com a fatura por emitir. Pode pagar até 20.00 €.']);

        $this->assertSame(9500, $this->saldo($vendor));

        $this->pagar($vendor, 20)->assertOk();
        $this->assertSame(7500, $this->saldo($vendor));
    }

    public function test_a_lista_de_pagamentos_diz_quanto_se_pode_pagar(): void
    {
        $vendor = $this->tecnico();
        $this->servicoFechado($vendor, 7500);
        $this->creditoPromocional($vendor, 2000);

        $this->comAuthDeAdmin()
            ->getJson('/api/v1/admin/vendor-payments')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $vendor->id)
            ->assertJsonPath('data.items.0.payable', 20)
            ->assertJsonPath('data.items.0.held_for_invoice', 75)
            ->assertJsonPath('data.items.0.payout_blocked', false);
    }

    // ------------------------------------------------------- a app

    public function test_a_app_ve_a_parte_retida_e_porque(): void
    {
        $vendor = $this->tecnicoSemAt();
        $this->servicoFechado($vendor, 7500);
        $this->creditoPromocional($vendor, 2000);

        $this->actingAs($vendor->user, 'api')
            ->getJson('/api/v1/vendor/stats')
            ->assertSuccessful()
            ->assertJsonPath('data.payout_blocked', false)
            ->assertJsonPath('data.payout_on_hold_amount', 7500)
            ->assertJsonPath('data.payout_hold_reason', 'at_user_missing');
    }
}
