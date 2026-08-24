<?php

use App\Models\Vendor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registo dos lembretes de perfil incompleto já enviados por técnico.
 *
 * Garantia de idempotência do comando vendors:remind-incomplete-profile.
 * A chave única (técnico, dia após o registo) é imposta pela BD: correr o
 * comando duas vezes no mesmo dia — ou duas instâncias em paralelo — nunca
 * duplica o mesmo lembrete.
 *
 * Não há coluna de "estado do perfil" na chave de propósito: o lembrete de D+1
 * é único para sempre. Se o técnico completar o perfil e algo voltar a faltar
 * mais tarde (um documento a expirar, por exemplo), quem avisa é o fluxo
 * próprio dessa falha, não este — que existe só para a ativação inicial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_onboarding_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Vendor::class)->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('days_after_signup');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'days_after_signup'], 'vendor_onboarding_reminder_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_onboarding_reminders');
    }
};
