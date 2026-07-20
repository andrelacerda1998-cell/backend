<?php

namespace App\Events\Customer\Schedule;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AcceptScheduleEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $user_customer_id;

    public array $schedule_details;

    public function __construct(int $user_customer_id, $schedule_details)
    {
        $this->user_customer_id = $user_customer_id;
        $this->schedule_details = $schedule_details;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('service.customer.'.$this->user_customer_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'AcceptScheduleEvent';
    }
}
