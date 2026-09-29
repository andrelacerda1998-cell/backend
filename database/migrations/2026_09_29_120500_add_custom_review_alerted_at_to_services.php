<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carimbo do aviso ao backoffice sobre um pedido personalizado parado.
 *
 * Existe para o aviso sair UMA vez. O `matching:advance` corre de minuto a
 * minuto; sem isto, um pedido esquecido enchia a caixa de correio de quem o
 * devia despachar — e um aviso repetido todas as horas ensina-se a ignorar,
 * que é exactamente o oposto do que se quer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->timestamp('custom_review_alerted_at')->nullable()->after('custom_dispatched_at');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('custom_review_alerted_at');
        });
    }
};
