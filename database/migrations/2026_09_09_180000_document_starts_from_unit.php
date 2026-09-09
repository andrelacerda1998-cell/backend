<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Declara no ESQUEMA que o `starts_from` esta em euros.
 *
 * A coluna nasceu como `unsignedInteger('desde')` sem comentario, sem cast e
 * sem validacao de unidade. O unico sitio em todo o projeto que dizia se eram
 * euros ou centimos era um rotulo de interface em portugues — "Desde (€)" —
 * num ficheiro de traducoes do backoffice.
 *
 * Isso custou caro a 09/09/2026: com a base de desenvolvimento a guardar
 * centimos (7500) e a producao a guardar euros (75), tirou-se o `* 100` da app
 * do cliente por engano. Em producao a Home teria mostrado "Desde 0,75 €".
 * Nada no codigo contradizia a leitura errada, porque nada no codigo dizia
 * seja o que for sobre a unidade.
 *
 * Um comentario de coluna viaja com o esquema e aparece em qualquer cliente de
 * base de dados — e o unico sitio onde quem esta a olhar para os dados o ve
 * sem ter de procurar. Nao muda nada em execucao: nao ha conversao aqui, nem
 * deve haver. Os valores da producao ja estao certos.
 */
return new class extends Migration
{
    private const COMENTARIO = 'Preco "desde" em EUROS (nao centimos). A app multiplica por 100 antes de formatar.';

    public function up(): void
    {
        Schema::table('services_types', function (Blueprint $table) {
            // Repete-se a definicao inteira de proposito: um `change()` que
            // omita atributos reescreve-os pelos valores por omissao, e esta
            // coluna e `unsigned`, `nullable` e sem default.
            $table->unsignedInteger('starts_from')->nullable()->comment(self::COMENTARIO)->change();
        });
    }

    public function down(): void
    {
        Schema::table('services_types', function (Blueprint $table) {
            $table->unsignedInteger('starts_from')->nullable()->comment('')->change();
        });
    }
};
