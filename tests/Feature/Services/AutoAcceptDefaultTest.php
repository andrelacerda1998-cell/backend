<?php

namespace Tests\Feature\Services;

use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A auto-aceitação saiu a 15/09/2026.
 *
 * Era um interruptor que respondia "sim" por conta do profissional, sem ele
 * ver que serviço era, quanto rendia nem onde ficava — e a etapa de resposta,
 * que é o que dá sentido ao fluxo de seleção, perdia-se. Aceitar às cegas
 * também o expunha a faltar a um trabalho que nunca escolheu.
 *
 * A coluna `auto_accept` fica na base de dados, por não se apagarem dados de
 * ninguém, mas ninguém a lê para decidir nada: escreve-se sempre `false`. Este
 * teste existe para o provar — se voltar a haver um caminho que aceite sozinho,
 * falha aqui.
 */
class AutoAcceptDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_coluna_nasce_desligada_e_assim_fica(): void
    {
        $vendor = Vendor::factory()->create();

        $this->assertGreaterThan(0, $vendor->scheduleAvailable()->count());
        $this->assertSame(
            0,
            $vendor->scheduleAvailable()->where('auto_accept', true)->count(),
        );
    }

    public function test_o_modelo_ja_nao_sabe_responder_por_ninguem(): void
    {
        // O método que decidia aceitar sozinho deixou de existir. É a garantia
        // mais forte que se pode ter em PHP de que nada o chama.
        $this->assertFalse(method_exists(Vendor::class, 'autoAcceptsOn'));
    }

    public function test_a_disponibilidade_semanal_continua_a_ser_criada(): void
    {
        // Continua a servir para as folgas e para o que o profissional vê na
        // agenda — só deixou de responder por ele.
        $vendor = Vendor::factory()->create();

        $this->assertSame(7, $vendor->scheduleAvailable()->count());
    }
}
