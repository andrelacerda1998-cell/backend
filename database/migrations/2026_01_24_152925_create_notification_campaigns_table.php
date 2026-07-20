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
        Schema::create('notification_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('title'); // Multi-language support
            $table->json('body'); // Multi-language support
            $table->enum('target_type', ['vendor', 'customer', 'both'])->default('both');
            $table->enum('user_status', ['online', 'offline', 'both'])->nullable();
            $table->enum('frequency_type', ['once', 'daily', 'weekly', 'custom'])->default('once');
            $table->integer('frequency_value')->nullable(); // numeric value for custom frequency
            $table->enum('frequency_unit', ['minutes', 'hours', 'days'])->nullable(); // unit for custom frequency
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('next_send_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_campaigns');
    }
};
