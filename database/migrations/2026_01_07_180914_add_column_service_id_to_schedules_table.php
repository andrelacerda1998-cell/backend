<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('schedule', 'service_id')) {
            return;
        }

        Schema::table('schedule', function (Blueprint $table) {
            $table->foreignId('service_id')
                ->constrained('services', 'id')
                ->after('service_type_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
        });
    }
};
