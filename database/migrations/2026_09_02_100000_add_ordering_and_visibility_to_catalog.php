<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ordem, visibilidade e destaque no catálogo.
 *
 * A app mostrava as categorias por ordem alfabética e escolhia os "serviços
 * populares" pelos primeiros que o servidor devolvia — o backoffice não tinha
 * como mandar em nenhuma das duas coisas. Estes três campos passam essa decisão
 * para quem gere o catálogo.
 *
 * Defaults escolhidos para não mudar nada ao aplicar: tudo activo, ordem 0
 * (mantém-se o desempate alfabético) e nada em destaque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_areas', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('name');
            $table->boolean('is_active')->default(true)->after('sort_order');
        });

        Schema::table('services_types', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('name');
            $table->boolean('is_active')->default(true)->after('sort_order');
            // Destaque na Home. Separado de sort_order porque a ordem no
            // destaque não tem de ser a ordem dentro da categoria.
            $table->boolean('is_popular')->default(false)->after('is_active');
            $table->unsignedInteger('popular_order')->default(0)->after('is_popular');
        });
    }

    public function down(): void
    {
        Schema::table('operation_areas', function (Blueprint $table) {
            $table->dropColumn(['sort_order', 'is_active']);
        });

        Schema::table('services_types', function (Blueprint $table) {
            $table->dropColumn(['sort_order', 'is_active', 'is_popular', 'popular_order']);
        });
    }
};
