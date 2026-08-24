<?php

namespace App\Events\Matching;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Convite para um pedido: o profissional foi chamado e a janela dele está a correr.
 *
 * Ver docs/matching.md.
 */
class MatchingInvitationEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly array $payload,
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('service.vendor.'.$this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'MatchingInvitationEvent';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
