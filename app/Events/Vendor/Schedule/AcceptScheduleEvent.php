<?php

namespace App\Events\Vendor\Schedule;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AcceptScheduleEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $user_id;

    public array $schedule_details;

    public function __construct(int $user_id, $schedule_details)
    {
        $this->user_id = $user_id;
        $this->schedule_details = $schedule_details;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('service.vendor.'.$this->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'AcceptScheduleEvent';
    }
}
