<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Filtros de publico-alvo para as campanhas.
 *
 * Ate aqui davam para escolher tecnicos/clientes e online/offline. Isso chega
 * para avisos gerais, mas nao para as campanhas que valem mesmo a pena — as que
 * falam com quem esta encravado nalgum sitio concreto:
 *
 *   vendor_eligibility        quem nao consegue aceitar servicos (registo por
 *                             acabar, documentos por aprovar, AT invalida)
 *   vendor_missing_schedule   tecnicos sem morada de agendamento: enquanto nao
 *                             a tiverem, o preco dos agendados sai da morada
 *                             fiscal, que pode ser o contabilista
 *   inactive_days             sem servicos ha N dias — vale para os dois lados
 *   customer_never_requested  clientes que se registaram e nunca pediram nada
 *
 * Todos anulaveis: a null nao filtram, e as campanhas que ja existem continuam
 * a alcancar exatamente quem alcancavam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->string('vendor_eligibility')->nullable()->after('user_status');
            $table->boolean('vendor_missing_schedule_address')->nullable()->after('vendor_eligibility');
            $table->unsignedSmallInteger('inactive_days')->nullable()->after('vendor_missing_schedule_address');
            $table->boolean('customer_never_requested')->nullable()->after('inactive_days');
        });
    }

    public function down(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->dropColumn([
                'vendor_eligibility',
                'vendor_missing_schedule_address',
                'inactive_days',
                'customer_never_requested',
            ]);
        });
    }
};
