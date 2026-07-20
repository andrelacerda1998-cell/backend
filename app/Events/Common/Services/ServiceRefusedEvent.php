<?php

namespace App\Events\Common\Services;

use App\Models\Service;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServiceRefusedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(private readonly User $customer, public Service $service) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('common.services.'.$this->service->id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ServiceRefusedEvent';
    }
}
