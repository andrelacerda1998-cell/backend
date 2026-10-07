<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fecho automático e "Reportar um problema".
 *
 * - `finished_at`: quando o técnico carregou em "Concluir". Até aqui não havia
 *   onde contar as 24h — o `updated_at` mexe com qualquer gravação.
 * - `auto_closed_at`: o serviço foi fechado pelo sistema, não pelo cliente.
 *   Distingue os dois nas métricas e no suporte.
 * - `problem_*`: o cliente (ou o técnico) disse que algo correu mal. Enquanto
 *   houver um problema reportado, o fecho automático não cobra nada — decide
 *   uma pessoa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->timestamp('finished_at')->nullable()->after('arrived_at');
            $table->timestamp('auto_closed_at')->nullable()->after('finished_at');
            $table->timestamp('problem_reported_at')->nullable()->after('auto_closed_at');
            $table->string('problem_reported_by', 16)->nullable()->after('problem_reported_at');
            $table->string('problem_reason', 32)->nullable()->after('problem_reported_by');
            $table->text('problem_message')->nullable()->after('problem_reason');
            $table->index(['status', 'finished_at']);
        });

        // Os que já estão concluídos à espera do cliente contam a partir de
        // AGORA, e não da última gravação. Com o `updated_at`, o primeiro fecho
        // automático depois do deploy cobrava de uma vez todos os serviços
        // antigos por confirmar — sem o cliente ter sido avisado de que isso
        // ia acontecer. Assim têm as mesmas 24 horas que um serviço novo.
        DB::table('services')
            ->where('status', 'Finished')
            ->whereNull('finished_at')
            ->update(['finished_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropIndex(['status', 'finished_at']);
            $table->dropColumn([
                'finished_at', 'auto_closed_at', 'problem_reported_at',
                'problem_reported_by', 'problem_reason', 'problem_message',
            ]);
        });
    }
};
