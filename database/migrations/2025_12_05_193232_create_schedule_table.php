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
        if (Schema::hasTable('schedule')) {
            return;
        }

        Schema::create('schedule', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors', 'id');
            $table->foreignId('customer_id')->constrained('users', 'id');
            $table->foreignId('service_type_id')->constrained('services_types', 'id');
            $table->date('scheduled_day');
            $table->time('scheduled_time_start');
            $table->time('scheduled_time_end');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule');
    }
};
