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
        Schema::table('notification_campaign_logs', function (Blueprint $table) {
            $table->timestamp('opened_at')->nullable()->after('sent_at');
            $table->timestamp('deep_link_clicked_at')->nullable()->after('opened_at');
        });
    }

    public function down(): void
    {
        Schema::table('notification_campaign_logs', function (Blueprint $table) {
            $table->dropColumn(['opened_at', 'deep_link_clicked_at']);
        });
    }
};
