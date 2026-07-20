<?php

namespace App\Events\Vendor\Schedule;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServiceScheduledEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $user_id;

    public array $scheduleDetails;

    public function __construct(int $user_id, array $scheduleDetails)
    {
        $this->user_id = $user_id;
        $this->scheduleDetails = $scheduleDetails;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('service.vendor.'.$this->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ServiceScheduledEvent';
    }
}
