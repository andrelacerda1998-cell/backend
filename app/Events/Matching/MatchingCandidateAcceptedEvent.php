<?php

namespace App\Events\Matching;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Alguém aceitou. Vai ao ecrã do cliente à medida que entra, para a espera ser progressiva e não bloqueante.
 *
 * Ver docs/matching.md.
 */
class MatchingCandidateAcceptedEvent implements ShouldBroadcast
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
            new PrivateChannel('service.customer.'.$this->userId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'MatchingCandidateAcceptedEvent';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
