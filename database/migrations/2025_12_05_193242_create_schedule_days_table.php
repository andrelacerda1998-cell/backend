<?php

use App\Enums\Schedule\ScheduleDay;
use App\Models\Schedule\ScheduleDays;
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
        if (Schema::hasTable('schedule_days')) {
            return;
        }

        Schema::create('schedule_days', function (Blueprint $table) {
            $table->id();
            $table->string('day_name');
            $table->timestamps();
        });

        $days = [
            ScheduleDay::MONDAY,
            ScheduleDay::TUESDAY,
            ScheduleDay::WEDNESDAY,
            ScheduleDay::THURSDAY,
            ScheduleDay::FRIDAY,
            ScheduleDay::SATURDAY,
            ScheduleDay::SUNDAY,
        ];
        foreach ($days as $day) {
            ScheduleDays::query()->create([
                'day_name' => $day
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_days');
    }
};
