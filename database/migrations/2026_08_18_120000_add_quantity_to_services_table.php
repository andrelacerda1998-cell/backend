<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unidades do mesmo serviço num pedido ("2 reparações de torneira").
 *
 * Default 1 e NOT NULL: todos os serviços que já existem são de uma unidade, e
 * um nulo aqui obrigaria cada leitor de preço a lembrar-se de tratar o caso —
 * mais cedo ou mais tarde alguém multiplicaria por null e o valor sairia a zero.
 *
 * unsignedSmallInteger e não integer: não há pedido legítimo com dezenas de
 * milhares de unidades, e o limite real (10) é validado no pedido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->unsignedSmallInteger('quantity')->default(1)->after('services_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('quantity');
        });
    }
};
