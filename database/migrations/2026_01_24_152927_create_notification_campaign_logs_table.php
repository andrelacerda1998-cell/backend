<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('notification_campaign_logs')) {
            Schema::create('notification_campaign_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('notification_campaign_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->timestamp('sent_at');
                $table->boolean('success')->default(true);
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->index(['notification_campaign_id', 'user_id', 'sent_at'], 'campaign_logs_campaign_user_sent_idx');
            });
        } else {
            // Table exists but might be missing the index, add it if it doesn't exist
            Schema::table('notification_campaign_logs', function (Blueprint $table) {
                $indexExists = DB::select("SHOW INDEX FROM notification_campaign_logs WHERE Key_name = 'campaign_logs_campaign_user_sent_idx'");
                if (empty($indexExists)) {
                    $table->index(['notification_campaign_id', 'user_id', 'sent_at'], 'campaign_logs_campaign_user_sent_idx');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_campaign_logs');
    }
};
