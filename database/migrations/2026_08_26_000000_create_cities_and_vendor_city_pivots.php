<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogo de cidades onde os tecnicos indicam disponibilidade, mais os dois
 * conjuntos por tecnico: onde aceita trabalhar (available) e o top 3 de maior
 * interesse (preferred). Alimenta o ranking de potencial de abertura no
 * backoffice -- por isso e uma lista fechada de cidades reais, nao texto livre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('district');
            // As sugeridas aparecem em destaque no ecra sem pesquisa; as
            // restantes so surgem pela pesquisa/autocomplete.
            $table->boolean('suggested')->default(false);
            $table->timestamps();

            $table->unique(['name', 'district']);
            $table->index('suggested');
        });

        Schema::create('vendor_available_cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['vendor_id', 'city_id']);
        });

        Schema::create('vendor_preferred_cities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained('cities')->cascadeOnDelete();
            // 1..3 -- ordem de prioridade do tecnico.
            $table->unsignedTinyInteger('position')->default(1);
            $table->timestamps();

            $table->unique(['vendor_id', 'city_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_preferred_cities');
        Schema::dropIfExists('vendor_available_cities');
        Schema::dropIfExists('cities');
    }
};
