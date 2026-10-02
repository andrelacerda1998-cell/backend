<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O comentário que o PROFISSIONAL escreve sobre o cliente.
 *
 * Havia `rating_comment_by_customer` mas não o par dele: o técnico só podia dar
 * estrelas. Uma nota baixa sem uma linha a dizer porquê não serve para nada a
 * quem tem de decidir se faz alguma coisa com ela -- "3 estrelas" não distingue
 * um cliente que não estava em casa de um que discutiu o preço à porta.
 *
 * Nullable: avaliar continua a ser opcional, e comentar é opcional dentro
 * disso. `text` como o do cliente, pelo mesmo motivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->text('rating_comment_by_vendor')->nullable()->after('rating_by_vendor');
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('rating_comment_by_vendor');
        });
    }
};
