<?php

namespace App\DTO\Schedule;

use App\Models\Schedule\Schedule;
use Illuminate\Support\Facades\Date;

readonly class ScheduleEventData
{
    public function __construct(
        public int $schedule_id,
        public int $vendor_id,
        public int $customer_id,
        public Date $scheduled_day,
        public string $customer_name,
        public array $service_type,
        public int $service_id,
        public string $customer_address,
        public string $scheduled_time_start,
        public string $scheduled_time_end,
    ) {}

    public static function fromModel(Schedule $schedule): self
    {
        return new self(
            schedule_id: $schedule->id,
            vendor_id: $schedule->vendor->id,
            customer_id: $schedule->customer->id,
            scheduled_day: $schedule->scheduled_day,
            customer_name: $schedule->customer->name,
            service_type: [
                'id' => $schedule->serviceType->id,
                'name' => $schedule->serviceType->name,
            ],
            service_id: $schedule->service_id,
            customer_address: $schedule->customer->mainAddress()->name,
            scheduled_time_start: $schedule->scheduled_time_start,
            scheduled_time_end: $schedule->scheduled_time_end,
        );
    }

    public function toArray(): array
    {
        return [
            'schedule_id' => $this->schedule_id,
            'vendor_id' => $this->vendor_id,
            'customer_id' => $this->customer_id,
            'scheduled_day' => $this->scheduled_day,
            'customer_name' => $this->customer_name,
            'service_type' => $this->service_type,
            'service_id' => $this->service_id,
            'customer_address' => $this->customer_address,
            'scheduled_time_start' => $this->scheduled_time_start,
            'scheduled_time_end' => $this->scheduled_time_end,
        ];
    }
}
