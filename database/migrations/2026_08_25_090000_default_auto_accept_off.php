<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A auto-aceitação passa a nascer DESLIGADA.
 *
 * Com o fluxo de seleção, tê-la ligada significa responder que sim a todos os
 * pedidos de serviço em nome do profissional. Isso é uma escolha dele, e o
 * valor por omissão da coluna fazia com que fosse tomada por ele — sem nunca
 * lhe ser perguntada.
 *
 * NÃO mexe nas linhas existentes: quem já a tem ligada pode estar a contar com
 * ela no fluxo antigo (agendamentos aceites sem responder). Mudar isso é uma
 * decisão de produto e de comunicação, não uma migração silenciosa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_available', function (Blueprint $table) {
            $table->boolean('auto_accept')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_available', function (Blueprint $table) {
            $table->boolean('auto_accept')->default(true)->change();
        });
    }
};
