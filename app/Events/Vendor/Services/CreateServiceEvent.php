<?php

namespace App\Events\Vendor\Services;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CreateServiceEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $user_vendor_id;

    public $serviceDetails;

    public function __construct(int $user_vendor_id, $serviceDetails)
    {
        $this->user_vendor_id = $user_vendor_id;
        $this->serviceDetails = $serviceDetails;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('service.vendor.'.$this->user_vendor_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'CreateServiceEvent';
    }
}
