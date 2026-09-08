<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Falta do tecnico a um servico marcado.
 *
 * Fica no proprio servico (e nao numa tabela a parte) porque e um facto sobre
 * ELE: aconteceu uma vez, ou nao aconteceu. O `vendor_no_show_at` e tambem a
 * guarda de idempotencia — e o que impede que a mesma falta seja cobrada duas
 * vezes por dois cliques no backoffice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->timestamp('vendor_no_show_at')->nullable()->after('on_the_way_at');
            // Em centimos, como todo o dinheiro nesta base de dados. Guardado e
            // nao recalculado: o valor do servico pode mudar depois, e o que foi
            // cobrado ao tecnico tem de continuar a ser o que foi cobrado.
            $table->integer('vendor_no_show_penalty')->nullable()->after('vendor_no_show_at');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['vendor_no_show_at', 'vendor_no_show_penalty']);
        });
    }
};
