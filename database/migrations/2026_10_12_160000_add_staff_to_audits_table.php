<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quem do staff fez cada alteração pelo backoffice.
 *
 * O backoffice fala com a API de admin com um token partilhado: nas
 * auditorias dessas alterações o `user_id` fica vazio e o feed de Atividade
 * dizia "Sistema" — ou nem as mostrava. Estas duas colunas guardam o que o
 * backoffice declara em X-Staff-Email / X-Staff-Nome (ver
 * App\Services\Auditoria\StaffDoBackoffice). As auditorias antigas ficam a
 * null: não há como saber, e não se inventa.
 *
 * No fim da tabela e anuláveis, de propósito: o MySQL 8 junta-as sem reescrever
 * a tabela (ALGORITHM=INSTANT), e a `audits` é grande. O índice é o que faz o
 * filtro por pessoa e a entrada destas linhas no feed não lerem a tabela
 * inteira; esse constrói-se em linha, sem bloquear escritas.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $tabela = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->table($tabela, function (Blueprint $table) {
            $table->string('staff_email', 190)->nullable();
            $table->string('staff_nome', 190)->nullable();
        });

        Schema::connection($connection)->table($tabela, function (Blueprint $table) {
            $table->index('staff_email');
        });
    }

    public function down(): void
    {
        $connection = config('audit.drivers.database.connection', config('database.default'));
        $tabela = config('audit.drivers.database.table', 'audits');

        Schema::connection($connection)->table($tabela, function (Blueprint $table) {
            $table->dropIndex(['staff_email']);
            $table->dropColumn(['staff_email', 'staff_nome']);
        });
    }
};
