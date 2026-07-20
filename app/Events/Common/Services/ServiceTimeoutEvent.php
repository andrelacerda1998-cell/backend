<?php

namespace App\Events\Common\Services;

use App\Models\Service;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServiceTimeoutEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public array $service) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('common.services.'.$this->service['id']),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ServiceTimeoutEvent';
    }
}
