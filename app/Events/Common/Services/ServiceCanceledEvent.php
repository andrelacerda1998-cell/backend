<?php

namespace App\Events\Common\Services;

use App\Models\Service;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServiceCanceledEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $service;

    public function __construct(array $service){
        $this->service = $service;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('common.services.'.$this->service['id']),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ServiceCanceledEvent';
    }
}
