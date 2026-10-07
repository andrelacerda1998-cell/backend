<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O candidato já atendeu este cliente (e correu bem).
 *
 * Fica gravado no candidato e não calculado à leitura: é o que explica porque
 * é que ele foi convidado fora da ordem do ranking, e permite medir se
 * "chamar quem já conhece" converte mais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_candidates', function (Blueprint $table) {
            $table->boolean('is_returning_vendor')->default(false)->after('is_new_vendor_slot');
        });
    }

    public function down(): void
    {
        Schema::table('service_candidates', function (Blueprint $table) {
            $table->dropColumn('is_returning_vendor');
        });
    }
};
