<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services_types', function (Blueprint $table) {
            $table->integer('suggested_price')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('services_types', function (Blueprint $table) {
            $table->dropColumn('suggested_price');
        });
    }
};
