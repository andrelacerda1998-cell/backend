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
        Schema::table('services_types', function (Blueprint $table) {
            //
            $table->json('includes')->nullable()->after('description');
            $table->json('excludes')->nullable()->after('includes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('services_types', function (Blueprint $table) {
            //
            $table->dropColumn('includes');
            $table->dropColumn('excludes');
        });
    }
};
