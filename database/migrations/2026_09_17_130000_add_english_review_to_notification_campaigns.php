<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quando e que alguem confirmou a traducao inglesa.
 *
 * O ingles pode ser preenchido por maquina — e um rascunho, nao uma mensagem
 * pronta. Enquanto ninguem o confirmar, quem tem o telemovel em ingles recebe o
 * PORTUGUES, que ja e o que acontecia antes de haver ingles nenhum.
 *
 * Isto e o que torna o rascunho seguro: sem a coluna, "rascunho" era so uma
 * etiqueta no backoffice e a traducao por rever saia a toda a gente na mesma.
 *
 * Nulo = por rever (ou sem ingles). Nao ha estado "aprovado automaticamente".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->timestamp('english_reviewed_at')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->dropColumn('english_reviewed_at');
        });
    }
};
