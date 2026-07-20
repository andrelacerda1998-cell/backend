<?php

namespace App\Events\Common\Services;

use App\Models\Service;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ArrivedServiceEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $serviceDetails;

    public function __construct(array $serviceDetails)
    {
        $this->serviceDetails = $serviceDetails;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('common.services.'.$this->serviceDetails['id']),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ServiceArrivedEvent';
    }
}
