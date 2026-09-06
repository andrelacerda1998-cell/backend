<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de que o lembrete de pagamento de uma ocorrencia recorrente ja foi
 * enviado.
 *
 * O comando corre de hora a hora dentro de uma janela de 24h (72h->48h antes do
 * servico); sem esta marca, o cliente recebia o mesmo aviso 24 vezes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('schedule') || Schema::hasColumn('schedule', 'payment_reminder_sent_at')) {
            return;
        }

        Schema::table('schedule', function (Blueprint $table) {
            $table->timestamp('payment_reminder_sent_at')->nullable()->after('recurrence_parent_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('schedule') || ! Schema::hasColumn('schedule', 'payment_reminder_sent_at')) {
            return;
        }

        Schema::table('schedule', function (Blueprint $table) {
            $table->dropColumn('payment_reminder_sent_at');
        });
    }
};
