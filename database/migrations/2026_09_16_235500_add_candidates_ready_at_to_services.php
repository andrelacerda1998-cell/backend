<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O instante em que o pedido passou a ter alguem para escolher.
 *
 * E o inicio do relogio do CLIENTE — a hora que ele tem para escolher e pagar,
 * e a contagem que ve no ecra.
 *
 * Coluna propria e nao `min(responded_at)` dos candidatos aceites: quando o
 * cliente escolhe, o escolhido passa a SELECTED e os outros a LOST, e o
 * conjunto "aceites" esvazia-se. A conta perdia o seu ponto de partida
 * exatamente na fase de pagamento — ou, pior, saltava para a resposta de outro
 * candidato e o contador GANHAVA tempo a meio. Um relogio que anda para tras e
 * tao mau como um que chega a zero sem nada acontecer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->timestamp('candidates_ready_at')->nullable()->after('custom_dispatched_at');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('candidates_ready_at');
        });
    }
};
