<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quando o TÉCNICO cancelou um serviço que já tinha aceitado.
 *
 * O cancelamento gravava só `Canceled`, com a mesma justificação de quando é o
 * cliente a desistir — não havia forma de contar quantas vezes um técnico
 * aceitou e depois largou. Sem essa contagem não há regra de fiabilidade
 * possível (ver Vendor::CANCELAMENTOS_ANTES_DA_PAUSA).
 *
 * Mesma forma do `vendor_no_show_at`: um carimbo, não um booleano, para a regra
 * poder contar por mês.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->timestamp('vendor_canceled_at')->nullable()->after('vendor_no_show_at');
            $table->index(['vendor_id', 'vendor_canceled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['vendor_id', 'vendor_canceled_at']);
            $table->dropColumn('vendor_canceled_at');
        });
    }
};
