<?php

namespace App\Observers;

use App\Models\Schedule\ScheduleDays;
use Illuminate\Support\Facades\Cache;

class ScheduleDaysObserver
{
    /**
     * Handle the ScheduleDays "created" event.
     */
    public function created(ScheduleDays $scheduleDays): void
    {
        //
    }

    /**
     * Handle the ScheduleDays "updated" event.
     */
    public function updated(ScheduleDays $scheduleDays): void
    {
        $scheduleDaysIDs = $scheduleDays::all()->pluck('id', 'day_name')->toArray();

        Cache::forever('schedule_days', $scheduleDaysIDs);
    }

    /**
     * Handle the ScheduleDays "deleted" event.
     */
    public function deleted(ScheduleDays $scheduleDays): void
    {
        //
    }

    /**
     * Handle the ScheduleDays "restored" event.
     */
    public function restored(ScheduleDays $scheduleDays): void
    {
        //
    }

    /**
     * Handle the ScheduleDays "force deleted" event.
     */
    public function forceDeleted(ScheduleDays $scheduleDays): void
    {
        //
    }
}
