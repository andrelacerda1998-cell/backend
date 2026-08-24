<?php

use App\Models\Vendor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dias em que o técnico está indisponível pontualmente (folga, doença, férias).
 *
 * A disponibilidade existente (schedule_available) é SEMANAL e fixa: dia da
 * semana + horário. Não havia forma de dizer "esta quarta não posso" sem
 * desligar o dia inteiro para sempre. O técnico acabava a recusar pedidos um a
 * um — e a taxa de aceitação dele caía por algo que não é recusa.
 *
 * Uma linha por (técnico, dia). O motivo é opcional e serve o backoffice, não
 * o cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_unavailable_days', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Vendor::class)->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'day'], 'vendor_unavailable_day_unique');
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_unavailable_days');
    }
};
