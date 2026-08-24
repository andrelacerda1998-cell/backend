<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quando o técnico chegou ao local e começou o serviço.
 *
 * Havia `on_the_way_at` (saída) mas não o início da execução. Sem isso, o
 * tempo decorrido só podia ser estimado por `updated_at` — que muda com
 * qualquer alteração ao serviço (fotos, extras) e portanto não serve para uma
 * contagem que o técnico vê ao segundo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->timestamp('arrived_at')->nullable()->after('on_the_way_at');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('arrived_at');
        });
    }
};
