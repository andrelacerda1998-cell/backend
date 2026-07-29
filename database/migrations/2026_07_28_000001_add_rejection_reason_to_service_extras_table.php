<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_extras', function (Blueprint $table) {
            // Motivo (opcional) escrito pelo cliente ao recusar um extra.
            $table->string('rejection_reason', 150)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('service_extras', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }
};
