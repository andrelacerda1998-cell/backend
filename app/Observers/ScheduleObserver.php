<?php

namespace App\Observers;

use App\Jobs\Services\ScheduleReminderJob;
use App\Models\Schedule\Schedule;
use Illuminate\Support\Carbon;

class ScheduleObserver
{
    public function updated(Schedule $schedule): void
    {
        if ($schedule->wasChanged('is_pending') && ! $schedule->is_pending) {
            $this->dispatchReminderJob($schedule);
        }
    }

    public function created(Schedule $schedule): void
    {
        if (! $schedule->is_pending) {
            $this->dispatchReminderJob($schedule);
        }
    }

    private function dispatchReminderJob(Schedule $schedule): void
    {
        // scheduled_day/scheduled_time_start são hora local de parede (Portugal). Interpretar em
        // Europe/Lisbon para o lembrete disparar no instante correto (evita desvio WET/WEST de 0/1h).
        $scheduledDateTime = Carbon::parse($schedule->scheduled_day, 'Europe/Lisbon')
            ->setTimeFromTimeString($schedule->scheduled_time_start);

        $reminderTime = $scheduledDateTime->copy()->subHour();

        if ($reminderTime->isFuture()) {
            ScheduleReminderJob::dispatch($schedule)->delay($reminderTime);
        }
    }
}
