<?php

namespace App\Events\Vendor\Services;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UpdateLocationEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public array $service, private int $vendorUserId)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("service.vendor.{$this->vendorUserId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'UpdateLocationEvent';
    }
}
