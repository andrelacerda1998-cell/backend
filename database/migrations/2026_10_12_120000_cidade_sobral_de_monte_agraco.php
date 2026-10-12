<?php

use Database\Seeders\PortugueseCitiesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O Sobral de Monte Agraço entra na lista de cidades do técnico.
 *
 * A Piquet passa a atender no Sobral com técnicos de Lisboa (decisão do
 * André, 12/10/2026). No matching, as cidades do técnico são a área dele: quem
 * escolheu só "Lisboa" não chega ao Sobral, a ~35 km do centro, fora dos 15 km
 * da cidade e dos 30 km do recurso. Para receber esses pedidos, o técnico tem
 * de poder escolher o Sobral, e só se escolhe o que está no catálogo.
 *
 * As coordenadas (centro da vila, OpenStreetMap) vão já aqui para não ficarem
 * à espera do `cities:geocode`, que se corre à mão no servidor. Sem elas, a
 * cidade só conta pelo nome que vem na morada.
 *
 * Correr o seeder outra vez não duplica nada (ver a migração que semeou o
 * catálogo): reafirma as sugeridas e recalcula as ativas a partir das zonas
 * abertas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cities')) {
            return;
        }

        (new PortugueseCitiesSeeder)->run();

        DB::table('cities')
            ->where('name', 'Sobral de Monte Agraço')
            ->where('district', 'Lisboa')
            ->whereNull('latitude')
            ->update(['latitude' => 39.0183500, 'longitude' => -9.1517100]);
    }

    /** Sem `down`, pela mesma razão da migração que semeou o catálogo. */
    public function down(): void {}
};
