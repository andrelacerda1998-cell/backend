<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmacao de presenca do tecnico, 72h antes do servico.
 *
 * Aceitar um agendamento e dizer "fico com ele"; dias depois, confirmar e dizer
 * "continuo a contar com ele". Sao coisas diferentes, e e a segunda que evita
 * que o cliente fique em casa a espera de alguem que se esqueceu.
 *
 * `vendor_reminder_sent_at` guarda que o aviso ja saiu: o comando corre de hora
 * a hora dentro de uma janela, e sem esta marca o tecnico recebia o mesmo
 * lembrete varias vezes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('schedule')) {
            return;
        }

        Schema::table('schedule', function (Blueprint $table) {
            if (! Schema::hasColumn('schedule', 'vendor_reminder_sent_at')) {
                $table->timestamp('vendor_reminder_sent_at')->nullable()->after('payment_reminder_sent_at');
            }
            if (! Schema::hasColumn('schedule', 'vendor_confirmed_at')) {
                $table->timestamp('vendor_confirmed_at')->nullable()->after('vendor_reminder_sent_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('schedule')) {
            return;
        }

        Schema::table('schedule', function (Blueprint $table) {
            foreach (['vendor_confirmed_at', 'vendor_reminder_sent_at'] as $column) {
                if (Schema::hasColumn('schedule', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
