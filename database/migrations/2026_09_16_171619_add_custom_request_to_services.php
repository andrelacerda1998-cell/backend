<?php

use App\Models\GeneralSettings\OperationArea;
use App\Models\Service;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido personalizado: um servico sem tipo de catalogo.
 *
 * O cliente descreve o que precisa; o backoffice decide quanto tempo leva e
 * que categorias de profissional o podem fazer; so depois disso o matching
 * arranca. `services_type_id` ja era nullable — o que faltava era o resto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('is_custom')->default(false)->after('services_type_id');
            $table->text('custom_description')->nullable()->after('is_custom');
            // Definido pelo backoffice, em minutos. E o `time` que um tipo de
            // catalogo teria: entra na mesma matematica de preco e de agenda.
            $table->unsignedInteger('custom_duration_minutes')->nullable()->after('custom_description');
            // Quando o backoffice enviou o pedido aos profissionais. E daqui
            // que conta o prazo global do pedido, nao do `created_at`: entre
            // um e outro podem passar horas de analise.
            $table->timestamp('custom_dispatched_at')->nullable()->after('custom_duration_minutes');
        });

        // As categorias que o backoffice escolheu para este pedido. Um pedido
        // personalizado pode tocar em mais do que uma ("montar um movel e
        // ligar a luz"), e sao elas que decidem quem e convidado.
        Schema::create('service_operation_area', function (Blueprint $table) {
            $table->foreignIdFor(Service::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(OperationArea::class)->constrained()->cascadeOnDelete();
            $table->primary(['service_id', 'operation_area_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_operation_area');
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['is_custom', 'custom_description', 'custom_duration_minutes', 'custom_dispatched_at']);
        });
    }
};
