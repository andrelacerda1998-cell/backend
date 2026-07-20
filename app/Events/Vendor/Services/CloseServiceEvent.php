<?php

namespace App\Events\Vendor\Services;

use App\Models\Service;
use App\Models\Vendor;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CloseServiceEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(private Vendor $vendor, public array $service) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('service.vendor.'.$this->vendor->user->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ServiceClosedEvent';
    }
}
