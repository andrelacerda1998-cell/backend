<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consentimento para comunicações de marketing.
 *
 * Guarda-se a DATA em que foi dado, e nao um booleano: o RGPD exige poder
 * demonstrar quando o consentimento foi obtido, e "true" nao prova nada.
 * `null` = nunca consentiu (ou retirou o consentimento) — por omissao nao se
 * envia nada, que e a unica posicao defensavel sem opt-in explicito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('marketing_consent_at')
                ->nullable()
                ->after('phone_number_verified_at')
                ->comment('Quando o utilizador aceitou receber comunicacoes de marketing. NULL = nao aceitou.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('marketing_consent_at');
        });
    }
};
