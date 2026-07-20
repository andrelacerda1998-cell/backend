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
        if (Schema::hasColumn('schedule', 'is_pending')) {
            return;
        }

        Schema::table('schedule', function (Blueprint $table) {
            $table->boolean('is_pending')->default(false)->after('scheduled_time_end');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('schedule', function (Blueprint $table) {
            $table->dropColumn('is_pending');
        });
    }
};
