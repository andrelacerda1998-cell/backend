<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O caminho de cada pedido, e não só onde parou.
 *
 * `services` guarda o estado ATUAL. Para saber quanto tempo um pedido esteve
 * à procura, quando o técnico saiu ou em que passo se perdeu, era preciso
 * adivinhar pelo `updated_at`. Ver App\Services\Operacoes\RegistoDeEventos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->string('tipo', 40);
            $table->string('estado_de', 40)->nullable();
            $table->string('estado_para', 40)->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->json('dados')->nullable();
            $table->timestamp('ocorreu_em')->useCurrent();

            $table->index(['service_id', 'ocorreu_em']);
            $table->index(['tipo', 'ocorreu_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_events');
    }
};
