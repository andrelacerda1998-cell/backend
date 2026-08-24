<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `vendor_ratings` media a coisa errada e inventava a nota em falta.
 *
 * Duas mudanças de estrutura:
 *
 *  - `average_rating` passa a NULLABLE. Não ter avaliações é um facto, e é
 *    diferente de ter má nota. Enquanto a coluna era NOT NULL, o código era
 *    obrigado a inventar um valor — e inventava 5.
 *  - passa a decimal(3,2). Era int com `round()`, o que transformava 4,5 em 5:
 *    a app do cliente já mostra uma casa decimal (`toFixed(1)`), portanto a
 *    precisão perdia-se sem necessidade nenhuma.
 *
 * As linhas existentes ficam a NULL: foram calculadas a partir de
 * `rating_by_vendor` — a nota que o PROFISSIONAL deu ao CLIENTE — e nenhuma
 * delas é recuperável. São recalculadas a seguir por `vendors:recalculate-ratings`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_ratings', function (Blueprint $table) {
            $table->decimal('average_rating', 3, 2)->nullable()->change();
        });

        DB::table('vendor_ratings')->update([
            'average_rating' => null,
            'total_ratings' => 0,
        ]);
    }

    public function down(): void
    {
        DB::table('vendor_ratings')->whereNull('average_rating')->update(['average_rating' => 5]);

        Schema::table('vendor_ratings', function (Blueprint $table) {
            $table->integer('average_rating')->nullable(false)->change();
        });
    }
};
