<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Candidatos a um serviço — ver docs/matching.md.
 *
 * Guardamos também quem perdeu, de propósito. Sem isso não há como responder a
 * "porque é que este pedido não me apareceu?" nem medir se o ranking está a
 * fazer o que se espera dele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('rank');
            $table->unsignedSmallInteger('wave')->default(1);
            $table->string('status')->default('shortlisted');

            // Faixa usada na ordenação. Gravada para a decisão ser auditável mais
            // tarde, mesmo depois de a avaliação do profissional ter mudado.
            $table->string('rating_band')->nullable();
            $table->unsignedInteger('rating_average')->nullable();
            $table->unsignedInteger('rating_count')->default(0);

            // Orçamento congelado. calculateHourCommission() depende da hora do
            // dia: sem congelar, um preço mostrado às 17:59 e pago às 18:01
            // mudava de banda. O preço mostrado é o preço cobrado.
            $table->unsignedInteger('quoted_amount')->nullable();
            $table->unsignedInteger('quoted_amount_for_vendor')->nullable();
            $table->decimal('quoted_distance', 8, 2)->nullable();

            // Ocupou a vaga reservada a quem tem poucas avaliações. Sem esta
            // vaga, oferta nova nunca entra: sem avaliações fica no fundo, e no
            // fundo nunca é escolhida para ganhar avaliações.
            $table->boolean('is_new_vendor_slot')->default(false);

            $table->timestamp('notified_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['service_id', 'vendor_id']);
            $table->index(['service_id', 'status']);
            $table->index(['vendor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_candidates');
    }
};
