<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quem aceitou o quê, em que versão e quando.
 *
 * Tabela própria e não colunas no utilizador: o que se precisa de provar não é
 * "aceitou", é "aceitou ESTA versão nesta data". Uma coluna que se sobrescreve
 * a cada versão nova apaga exactamente a prova que interessa quando alguém
 * contesta -- e a cláusula da perda do saldo é feita para ser contestada.
 *
 * Nunca se apaga nem se actualiza uma linha destas: cada aceitação é um facto
 * novo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Qual documento: os prestadores e os clientes têm os seus.
            $table->string('document', 64);
            $table->string('version', 32);
            $table->timestamp('accepted_at');
            /*
             * O que ele viu, não o que estava no servidor.
             *
             * Guarda-se o resumo (SHA-256) do texto apresentado. Se um dia o
             * documento for editado sem mudar de versão -- que não deve
             * acontecer, mas acontece -- é isto que distingue o que ele aceitou
             * do que passou a lá estar.
             */
            $table->string('content_digest', 64)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'document']);
            // Aceitar duas vezes a mesma versão é um duplo toque, não dois factos.
            $table->unique(['user_id', 'document', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_acceptances');
    }
};
