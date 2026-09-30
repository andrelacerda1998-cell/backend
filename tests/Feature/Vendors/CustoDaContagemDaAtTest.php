<?php

namespace Tests\Feature\Vendors;

use App\Enums\Vendors\StatusVendor;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A regra da AT não pode custar uma query por profissional.
 *
 * O `at_required` conta serviços, e o `can_accept_service` é lido para TODOS os
 * candidatos de um pedido. Se a contagem corresse sempre, cada pedido passava a
 * levar mais uma query por profissional avaliado — numa lista de 20, vinte.
 *
 * Por isso o portão é `at_ready || ! at_required` e não o contrário: o `||` do
 * PHP faz curto-circuito, e quem já deu a AT — que é quem já trabalha — nunca
 * chega a contar nada.
 *
 * Este teste existe para alguém não trocar a ordem por parecer mais legível.
 */
class CustoDaContagemDaAtTest extends TestCase
{
    use RefreshDatabase;

    private function vendor(bool $comAt): Vendor
    {
        $user = User::factory()->create([
            'is_test' => true,
            'email_verified_at' => now(),
            'phone_number_verified_at' => now(),
        ]);

        $vendor = Vendor::factory()->create([
            'user_id' => $user->id,
            'status' => StatusVendor::ONLINE,
            'iban' => 'PT50000000000000000000000',
            'invoice_workspace' => 'ws-'.$user->id,
            'at_user' => $comAt ? '999999999/1' : null,
        ]);

        if ($comAt) {
            $vendor->forceFill(['at_valid' => true])->save();
        }

        return $vendor->fresh();
    }

    /** Quantas vezes a tabela `services` foi contada durante o callback. */
    private function contagensDeServicos(callable $accao): int
    {
        $vezes = 0;
        DB::listen(function ($query) use (&$vezes) {
            if (str_contains($query->sql, 'count(*)') && str_contains($query->sql, '`services`')) {
                $vezes++;
            }
        });

        $accao();

        return $vezes;
    }

    public function test_quem_ja_deu_a_at_nao_paga_a_contagem(): void
    {
        $vendor = $this->vendor(comAt: true);

        $vezes = $this->contagensDeServicos(function () use ($vendor) {
            $vendor->fresh()->can_accept_service;
        });

        $this->assertSame(0, $vezes, 'o curto-circuito do || evita a contagem a quem já tem AT');
    }

    /** Quem não a deu paga UMA, não mais do que isso. */
    public function test_quem_nao_deu_paga_uma_contagem_so(): void
    {
        $vendor = $this->vendor(comAt: false);

        $vezes = $this->contagensDeServicos(function () use ($vendor) {
            $vendor->fresh()->can_accept_service;
        });

        $this->assertLessThanOrEqual(1, $vezes, 'uma contagem por avaliação, nunca mais');
    }

    /**
     * Dez profissionais com AT: zero contagens no total.
     *
     * É o cenário real de uma shortlist — e o que este ficheiro existe para
     * impedir que se degrade sem ninguém reparar.
     */
    public function test_uma_lista_de_dez_com_at_nao_conta_nada(): void
    {
        $vendors = collect(range(1, 10))->map(fn () => $this->vendor(comAt: true));

        $vezes = $this->contagensDeServicos(function () use ($vendors) {
            $vendors->each(fn (Vendor $v) => $v->fresh()->can_accept_service);
        });

        $this->assertSame(0, $vezes);
    }
}
