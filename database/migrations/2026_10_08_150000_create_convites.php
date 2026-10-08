<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programa de convites (5 € + 5 €). O dinheiro vive na Carteira
 * (CarteiraDoCliente); isto é quem convidou quem, e em que pé está.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('code', 12)->unique();
            $table->timestamps();
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_user_id')->constrained('users')->cascadeOnDelete();
            // Um cliente só pode ser convidado uma vez.
            $table->foreignId('referred_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('referred_phone', 30)->nullable();
            $table->string('code', 12);
            $table->foreignId('first_service_id')->nullable()->constrained('services')->nullOnDelete();
            // pendente -> concluido | anulado | sem_recompensa (quem convidou já tinha o limite do ano)
            $table->string('status', 20)->default('pendente');
            $table->string('cancel_reason', 120)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['referrer_user_id', 'status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('referral_codes');
    }
};
