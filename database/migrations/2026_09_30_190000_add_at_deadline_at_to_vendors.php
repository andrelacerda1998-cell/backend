<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quando é que o relógio dos 5 dias começou a contar para este técnico.
 *
 * Fica `null` enquanto a AT não for exigida, e volta a `null` assim que ele a
 * der. Guarda-se o INÍCIO e não o fim porque o prazo pode mudar (hoje são 5
 * dias) e a data de início é o facto; o fim é uma conta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->timestamp('at_deadline_started_at')->nullable()->after('at_valid');
            // Quando o saldo foi mesmo perdido. Preenchido uma vez só -- é o
            // guarda de idempotência do comando que executa o prazo.
            $table->timestamp('at_forfeited_at')->nullable()->after('at_deadline_started_at');
            $table->unsignedBigInteger('at_forfeited_amount')->nullable()->after('at_forfeited_at');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['at_deadline_started_at', 'at_forfeited_at', 'at_forfeited_amount']);
        });
    }
};
