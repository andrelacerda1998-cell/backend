<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `schedule.service_id` passa a aceitar null.
 *
 * Uma ocorrência de uma serie recorrente nasce ANTES de existir pagamento — e
 * portanto antes de existir serviço: e essa marcacao que o cliente confirma e
 * paga depois (ver CreateNextRecurrence e MaterializePendingSchedule). Com a
 * coluna NOT NULL, o comando diario rebentava ao criar a ocorrencia seguinte e
 * nenhuma serie avancava, com o erro so visivel nos logs.
 *
 * Todas as marcacoes existentes tem serviço, por isso a mudanca nao mexe em
 * dados: so deixa de exigir o que a serie ainda nao pode dar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('schedule') || ! Schema::hasColumn('schedule', 'service_id')) {
            return;
        }

        Schema::table('schedule', function (Blueprint $table) {
            $table->unsignedBigInteger('service_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('schedule') || ! Schema::hasColumn('schedule', 'service_id')) {
            return;
        }

        // Sem reverter para NOT NULL: as ocorrencias por confirmar tem service_id
        // nulo, e a migracao inversa falharia sobre elas.
    }
};
