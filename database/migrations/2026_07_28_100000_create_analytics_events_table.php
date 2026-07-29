<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eventos de produto da app dos técnicos.
 *
 * Guardados em casa, sem SDK de terceiros: evita uma dependência nova e mantém
 * os dados sob o RGPD da Piquet. NÃO guardar dados pessoais em `properties` —
 * só ids, contagens, estados e durações.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 64)->index();
            $table->string('platform', 16)->nullable();
            $table->string('app_version', 24)->nullable();
            $table->json('properties')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['name', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
