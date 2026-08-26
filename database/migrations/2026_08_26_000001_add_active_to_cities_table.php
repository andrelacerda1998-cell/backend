<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cidade "ativa" = onde a Piquet ja opera (espelha allowed_zone). Distingue,
 * no ecra de disponibilidade, as cidades que ja recebem pedidos das que ainda
 * nao abriram.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->boolean('active')->default(false)->after('suggested');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn('active');
        });
    }
};
