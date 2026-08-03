<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * URL de validação 3DS devolvida pelo Payshop (CreditCardValidationRequired::getUrl())
     * quando cobrar um extra aprovado exige autenticação do cliente. Antes era descartada —
     * sem a guardar, não há forma de a app voltar a mostrar o ecrã de confirmação de pagamento
     * mais tarde (ex.: se o cliente fechou a app a meio do 3DS).
     */
    public function up(): void
    {
        Schema::table('service_extras', function (Blueprint $table) {
            $table->text('payment_validation_url')->nullable()->after('payment_error');
        });
    }

    public function down(): void
    {
        Schema::table('service_extras', function (Blueprint $table) {
            $table->dropColumn('payment_validation_url');
        });
    }
};
