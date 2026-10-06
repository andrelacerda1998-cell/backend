<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As linhas de uma visita com vários serviços.
 *
 * Sem preço por linha, de propósito: o preço da visita é um só (minutos
 * totais × valor/hora do técnico + uma deslocação), e parti-lo por linha seria
 * inventar uma divisão que o técnico não faz. Os minutos ficam congelados no
 * pedido — o catálogo pode mudar o tempo de um tipo depois, e a visita já foi
 * cotada e agendada com o tempo de então.
 *
 * Um serviço sem linhas aqui é um pedido de um serviço só, como sempre foi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('services_type_id')->constrained('services_types');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('minutes');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['service_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_items');
    }
};
