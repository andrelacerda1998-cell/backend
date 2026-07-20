<?php

namespace App\Jobs\Services;

use App\Enums\Services\ServiceStatus;
use App\Models\Schedule\Schedule;
use App\Notifications\Customer\ScheduleReminderNotification as CustomerScheduleReminderNotification;
use App\Notifications\Vendor\ScheduleReminderNotification as VendorScheduleReminderNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ScheduleReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly Schedule $schedule) {}

    public function handle(): void
    {
        $this->schedule->refresh();

        if (! $this->schedule->exists) {
            return;
        }

        $service = $this->schedule->service;
        if (! $service) {
            return;
        }

        if (in_array($service->status, [ServiceStatus::CANCELED, ServiceStatus::CANCELED_MBWAY, ServiceStatus::CLOSED, ServiceStatus::REFUSED, ServiceStatus::REFUSED_MBWAY, ServiceStatus::EXPIRED_MBWAY])) {
            return;
        }

        $customer = $this->schedule->customer;
        if ($customer) {
            $customer->notify(new CustomerScheduleReminderNotification($this->schedule));
        }

        $vendor = $this->schedule->vendor;
        if ($vendor?->user) {
            $vendor->user->notify(new VendorScheduleReminderNotification($this->schedule));
        }
    }
}
