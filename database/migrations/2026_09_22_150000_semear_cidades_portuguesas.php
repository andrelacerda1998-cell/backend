<?php

use Database\Seeders\PortugueseCitiesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * O catalogo de cidades passa a chegar a producao.
 *
 * O `PortugueseCitiesSeeder` existe desde que o passo "em que cidades queres
 * trabalhar?" foi criado, e e chamado pelo `DatabaseSeeder` — que so corre com
 * `db:seed`. O deploy corre `php artisan migrate --force` e mais nada, por isso
 * as cidades existiam em todas as maquinas de desenvolvimento e em nenhuma de
 * producao.
 *
 * O efeito era o passo 2 de 6 do registo abrir sem uma unica cidade para
 * escolher: com o minimo de 3 por cumprir, o tecnico nao passava dali. E como
 * o catalogo vazio e a lista sugerida vazia se parecem, nem havia forma de
 * perceber que faltavam dados.
 *
 * Dados de referencia entram por migracao e nao por seeder precisamente por
 * isto: o que nao esta no caminho do deploy nunca chega a producao.
 *
 * O seeder e idempotente (`updateOrCreate` por nome+distrito), por isso correr
 * isto onde as cidades ja existem nao duplica nada — so reafirma as sugeridas
 * e recalcula as ativas a partir das zonas abertas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cities')) {
            return;
        }

        (new PortugueseCitiesSeeder)->run();
    }

    /**
     * Sem `down`: apagar o catalogo levaria com ele as escolhas que os
     * tecnicos ja fizeram (`available_cities`/`preferred_cities` apontam para
     * estes ids). Um rollback que destroi dados de utilizadores nao e um
     * rollback, e um acidente.
     */
    public function down(): void {}
};
