<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\City;
use Database\Seeders\PortugueseCitiesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O catálogo de cidades tem de existir sem ninguém correr nada à mão.
 *
 * O seeder existia e nunca chegou a produção: o deploy corre só
 * `migrate --force`, e o `db:seed` não faz parte de nenhum passo. O passo 2 de
 * 6 do registo abria sem uma única cidade, com um mínimo de 3 por cumprir —
 * ou seja, o registo não passava dali.
 */
class CidadesChegamAProducaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_as_migracoes_sozinhas_deixam_o_catalogo_preenchido(): void
    {
        // RefreshDatabase corre apenas as migrações. Se o catálogo depender do
        // seeder, isto fica a zero — que era exatamente o estado de produção.
        $this->assertGreaterThan(100, City::query()->count());
    }

    public function test_ha_cidades_sugeridas_para_o_ecra_mostrar_sem_pesquisa(): void
    {
        // Sem sugeridas o ecrã abre com a caixa de pesquisa e nada por baixo:
        // tecnicamente funcional, na prática um passo vazio.
        $this->assertSame(25, City::query()->where('suggested', true)->count());
    }

    public function test_as_sugeridas_cobrem_as_areas_metropolitanas(): void
    {
        foreach (['Lisboa', 'Porto', 'Braga', 'Coimbra', 'Faro'] as $cidade) {
            $this->assertTrue(
                City::query()->where('name', $cidade)->where('suggested', true)->exists(),
                "{$cidade} devia estar em destaque no onboarding",
            );
        }
    }

    public function test_correr_outra_vez_nao_duplica(): void
    {
        $antes = City::query()->count();

        (new PortugueseCitiesSeeder)->run();

        // `updateOrCreate` por nome+distrito. Importa porque a migração pode
        // correr onde as cidades já existem.
        $this->assertSame($antes, City::query()->count());
    }

    public function test_cada_cidade_tem_distrito(): void
    {
        // O ecrã agrupa por distrito; uma cidade sem ele cai num grupo vazio.
        $this->assertSame(0, City::query()->whereNull('district')->orWhere('district', '')->count());
    }
}
