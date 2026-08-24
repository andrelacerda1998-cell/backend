<?php

use App\Models\Service;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos de "serviço esquecido em execução" já enviados.
 *
 * Garantia de idempotência do comando services:notify-stuck-in-progress.
 * UNIQUE (serviço, limiar) imposto pela BD: o aviso das 3h e o das 24h são
 * distintos, mas cada um só sai uma vez por serviço.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_stuck_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Service::class)->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('hours_threshold');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['service_id', 'hours_threshold'], 'service_stuck_notif_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_stuck_notifications');
    }
};
