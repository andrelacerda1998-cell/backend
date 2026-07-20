<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('services_type_vendor', function (Blueprint $table) {
            $table->dropColumn('hourly_rate');
        });
    }

    public function down(): void
    {
        Schema::table('services_type_vendor', function (Blueprint $table) {
            $table->integer('hourly_rate')->nullable();
        });
    }
};
