<?php

namespace App\Events\Customer\Services;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class VendorHeadingToLocationEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $user_customer_id;

    public array $service;

    public function __construct(int $user_customer_id, array $service)
    {
        $this->user_customer_id = $user_customer_id;
        $this->service = $service;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('service.customer.'.$this->user_customer_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'VendorHeadingToLocationEvent';
    }
}
