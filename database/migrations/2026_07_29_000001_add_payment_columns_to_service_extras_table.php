<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rasto financeiro dos extras aprovados. Cada extra aprovado passa a ter uma
     * cobrança própria (ordem Payshop dedicada) e um crédito próprio ao técnico
     * no fecho do serviço — tudo auditável nestas colunas.
     */
    public function up(): void
    {
        Schema::table('service_extras', function (Blueprint $table) {
            // null (nunca tentado) | not_required (teste/0€) | paid | pending_confirmation
            // (MBWay push à espera do cliente) | requires_action (3DS/sem cartão gravado —
            // precisa de fluxo na app do cliente) | failed
            $table->string('payment_status')->nullable()->after('status');
            // Ordem Payshop dedicada ao extra (payshop_payments_orders.id). Sem FK para não
            // acoplar ao schema do SDK; unique = idempotência (1 extra ↔ no máximo 1 ordem).
            $table->unsignedBigInteger('payment_order_id')->nullable()->unique()->after('payment_status');
            $table->string('payment_error')->nullable()->after('payment_order_id');
            $table->timestamp('charged_at')->nullable()->after('payment_error');
            // Preenchido quando o valor do extra é depositado na carteira do técnico no fecho.
            // Guard de idempotência do crédito: só credita quando ainda é null.
            $table->timestamp('vendor_credited_at')->nullable()->after('charged_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_extras', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'payment_order_id', 'payment_error', 'charged_at', 'vendor_credited_at']);
        });
    }
};
