<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Carteira do cliente passa a ter duas partes.
 *
 * - "Saldo": a carteira Bavix que já existe (`default`). Guarda dinheiro do
 *   cliente — reembolsos — e nunca expira. Nada muda para ela.
 * - "Crédito de convites": uma segunda carteira Bavix (`convites`), com
 *   dinheiro que a Piquet põe lá (transferido da carteira do sistema) e que
 *   expira.
 *
 * O saldo de uma carteira não diz QUE parte expira QUANDO. Daí as duas
 * tabelas: cada crédito é uma linha com o seu prazo e o que ainda sobra, e
 * cada uso num serviço fica registado, para um cancelamento devolver o
 * crédito à linha de onde saiu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Ao lado do `credit_used`, que passa a ser só a parte do Saldo.
            $table->unsignedInteger('referral_credit_used')->default(0)->after('credit_used');
        });

        Schema::create('wallet_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('amount');      // cêntimos
            $table->unsignedInteger('remaining');   // cêntimos ainda por usar
            $table->timestamp('expires_at');
            $table->timestamp('expired_at')->nullable();
            $table->string('reason', 60);           // ex.: convite_convidado, convite_convidante
            $table->nullableMorphs('source');       // de onde veio (o convite, no futuro)
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });

        Schema::create('wallet_credit_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_credit_id')->constrained('wallet_credits')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->unsignedInteger('amount');
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();

            $table->index(['service_id', 'returned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_credit_usages');
        Schema::dropIfExists('wallet_credits');
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('referral_credit_used');
        });
    }
};
