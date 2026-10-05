<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * As cidades do técnico passam a ser a área de trabalho dele no matching.
 *
 * Até aqui serviam só para a página de densidade no backoffice: o matching
 * usava a última posição GPS e um raio de 50 km que se abria a toda a gente
 * quando não havia ninguém dentro. Quem escolhia "Lisboa" podia receber um
 * convite de Setúbal sem perceber porquê.
 *
 * - cities.latitude/longitude: o centro da cidade (preenchido por
 *   `cities:geocode`). Comparar nomes não chega: o catálogo mistura tamanhos
 *   ("Agualva-Cacém" ao lado de "Lisboa") e a morada do serviço diz "Cacém".
 * - cities.radius_km: quanto à volta do centro conta como "a cidade". 15 km
 *   por omissão; uma cidade grande pode ter mais.
 * - service_candidates.is_outside_area: convidado fora das cidades dele (o
 *   recurso, quando não havia ninguém dentro). O convite diz-lho.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('district');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedTinyInteger('radius_km')->default(15)->after('longitude');
        });

        Schema::table('service_candidates', function (Blueprint $table) {
            $table->boolean('is_outside_area')->default(false)->after('is_returning_vendor');
        });
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'radius_km']);
        });

        Schema::table('service_candidates', function (Blueprint $table) {
            $table->dropColumn('is_outside_area');
        });
    }
};
