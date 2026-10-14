<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quando se avisou o cliente de que este crédito de convites vai expirar.
 *
 * O aviso sai uma vez por crédito (carteira:avisar-expiracao). Sem esta
 * coluna, a tarefa diária voltava a avisar todos os dias da última semana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_credits', function (Blueprint $table) {
            $table->timestamp('expiry_warned_at')->nullable()->after('expired_at');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_credits', function (Blueprint $table) {
            $table->dropColumn('expiry_warned_at');
        });
    }
};
