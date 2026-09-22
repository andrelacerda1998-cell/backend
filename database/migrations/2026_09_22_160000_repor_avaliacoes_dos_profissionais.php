<?php

use App\Models\Vendor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Repoe as avaliacoes a partir das notas que os CLIENTES deram.
 *
 * A migracao de 24/08 esvaziou `vendor_ratings` de proposito: os valores que
 * la estavam vinham de `rating_by_vendor` — a nota que o profissional deu ao
 * cliente — e nenhum era recuperavel. Deixou o recalculo para o comando
 * `vendors:recalculate-ratings`.
 *
 * Esse comando nunca correu em producao, e nao ia correr: o deploy sobe o
 * contentor e o entrypoint corre `migrate --force` e mais nada. O resultado e
 * que as avaliacoes estao vazias em producao desde 24/08 — nao por falta de
 * notas, mas por falta de alguem a fazer a soma.
 *
 * O mesmo que aconteceu ao catalogo de cidades, pelo mesmo motivo: o que nao
 * esta no caminho do deploy nao acontece. Por isso vai por migracao.
 *
 * E idempotente por natureza — recalcular duas vezes da o mesmo resultado — e
 * o comando continua a existir para quem precise de o correr a mao.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vendor_ratings') || ! Schema::hasTable('vendors')) {
            return;
        }

        Vendor::with('operationAreas')->chunkById(100, function ($vendors) {
            foreach ($vendors as $vendor) {
                try {
                    $vendor->updateRatting();
                } catch (Throwable $e) {
                    // Um profissional com dados incompletos nao pode impedir o
                    // deploy inteiro. Fica sem nota — que e o estado em que ja
                    // estava — e o erro fica registado.
                    report($e);
                }
            }
        });
    }

    /**
     * Sem `down`: o que isto escreve e um valor derivado, recalculavel a
     * qualquer momento. Apaga-lo no rollback so devolveria o ecra vazio.
     */
    public function down(): void {}
};
