<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Encomenda: o cesto depois de pedido.
 *
 * Não guarda dinheiro. Cada visita é um `Service` com o seu técnico, a sua
 * cativação e a sua cobrança — a encomenda só diz que essas visitas nasceram
 * do mesmo cesto, para a app as mostrar juntas e para se poderem cancelar
 * juntas enquanto ainda ninguém pagou nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users');
            $table->json('address');
            $table->string('mode', 16);
            $table->date('scheduled_day')->nullable();
            $table->string('scheduled_time_start', 8)->nullable();
            $table->string('status', 16)->default('open');
            $table->boolean('is_test')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_orders');
    }
};
