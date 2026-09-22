<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `schedule.service_type_id` passa a aceitar null.
 *
 * Um pedido PERSONALIZADO não tem tipo de serviço — `services.services_type_id`
 * já é nullable por isso mesmo. A marcação correspondente não conseguia nascer:
 * o cliente escolhia dia e hora, pagava, e o insert falhava nesta coluna.
 *
 * Não mexe em linhas nenhumas. Só deixa de exigir o que um personalizado não
 * tem para dar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule', function (Blueprint $table) {
            $table->unsignedBigInteger('service_type_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('schedule', function (Blueprint $table) {
            $table->unsignedBigInteger('service_type_id')->nullable(false)->change();
        });
    }
};
