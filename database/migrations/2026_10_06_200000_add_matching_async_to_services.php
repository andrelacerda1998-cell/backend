<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Se o pedido segue o regime assíncrono dos agendados com antecedência.
 *
 * Decidido UMA vez, quando o pedido entra em seleção, e gravado aqui — e não
 * recalculado a cada leitura a partir da hora marcada. Recalculado, um pedido
 * feito com 25 h de antecedência mudava de regras uma hora depois, a meio dos
 * convites: técnicos convidados com 2 h para responder e um pedido que de
 * repente passava a morrer em minutos.
 *
 * Ver `MatchingSettings::$async_lead_hours`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('matching_async')->default(false)->after('is_custom');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('matching_async');
        });
    }
};
