<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotência da deteção de não-comparência: uma linha por (serviço, etapa),
 * gravada ANTES do envio, com UNIQUE. Garante que cada etapa (vendor/customer/ops)
 * dispara no máximo uma vez por serviço, mesmo com o comando a correr ao minuto.
 * Mesmo padrão do service_stuck_notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('service_no_show_notifications')) {
            return;
        }

        Schema::create('service_no_show_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services', 'id')->cascadeOnDelete();
            $table->string('stage'); // vendor | customer | ops
            $table->timestamp('notified_at');
            $table->timestamps();

            $table->unique(['service_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_no_show_notifications');
    }
};
