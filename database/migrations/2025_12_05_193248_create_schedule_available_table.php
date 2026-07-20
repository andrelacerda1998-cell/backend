<?php

use App\Enums\Schedule\ScheduleDay;
use App\Models\Schedule\ScheduleAvailable;
use App\Models\Schedule\ScheduleDays;
use App\Models\Vendor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('schedule_available')) {
            return;
        }

        Schema::create('schedule_available', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors', 'id');
            $table->foreignId('day_id')->constrained('schedule_days', 'id');
            $table->boolean('auto_accept')->default(true);
            $table->boolean('is_enabled')->default(true);
            $table->time('time_start')->default('08:00:00');
            $table->time('time_end')->default('19:00:00');
            $table->timestamps();
        });

        Vendor::query()->chunk(100, function ($chunk) {
            $chunk->each(function ($vendor) {
                $days = ScheduleDays::query()->pluck('id', 'day_name')->toArray();
                Cache::forever('schedule_days', $days);

                foreach ($days as $dayName => $dayId) {
                    $enabled = true;
                    if ($dayName === ScheduleDay::SATURDAY->value || $dayName === ScheduleDay::SUNDAY->value) {
                        $enabled = false;
                    }

                    ScheduleAvailable::query()->create([
                        'vendor_id' => $vendor->id,
                        'day_id' => $dayId,
                        'auto_accept' => true,
                        'is_enabled' => $enabled,
                    ]);
                }
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_available');
    }
};
