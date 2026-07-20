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
        Schema::create('notification_campaign_opt_outs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('notification_campaign_id')->nullable()->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['user_id', 'notification_campaign_id'], 'opt_outs_user_campaign_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_campaign_opt_outs');
    }
};
