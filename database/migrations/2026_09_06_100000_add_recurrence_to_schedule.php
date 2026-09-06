<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repetição de um agendamento.
 *
 * `recurrence` guarda a regra escolhida pelo cliente (weekly, biweekly,
 * monthly). `recurrence_parent_id` liga cada ocorrência à marcação que a
 * originou, para se poder ver a série e parar de a continuar.
 *
 * A marcação seguinte só é criada depois desta se realizar — não se prende a
 * agenda do técnico nem se cobra meses à frente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('schedule')) {
            return;
        }

        Schema::table('schedule', function (Blueprint $table) {
            if (! Schema::hasColumn('schedule', 'recurrence')) {
                $table->string('recurrence', 20)->nullable()->after('scheduled_time_end');
            }
            if (! Schema::hasColumn('schedule', 'recurrence_parent_id')) {
                $table->unsignedBigInteger('recurrence_parent_id')->nullable()->after('recurrence');
                $table->index('recurrence_parent_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('schedule')) {
            return;
        }

        Schema::table('schedule', function (Blueprint $table) {
            if (Schema::hasColumn('schedule', 'recurrence_parent_id')) {
                $table->dropIndex(['recurrence_parent_id']);
                $table->dropColumn('recurrence_parent_id');
            }
            if (Schema::hasColumn('schedule', 'recurrence')) {
                $table->dropColumn('recurrence');
            }
        });
    }
};
