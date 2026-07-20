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
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->dropColumn('deep_link');
            $table->string('deep_link_type')->nullable()->after('body');
            $table->unsignedBigInteger('deep_link_id')->nullable()->after('deep_link_type');
        });
    }

    public function down(): void
    {
        Schema::table('notification_campaigns', function (Blueprint $table) {
            $table->dropColumn(['deep_link_type', 'deep_link_id']);
            $table->string('deep_link')->nullable()->after('body');
        });
    }
};
