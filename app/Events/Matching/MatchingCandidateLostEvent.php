<?php

namespace App\Events\Matching;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * O cliente escolheu outro. Avisado em segundos, nunca deixado a adivinhar.
 *
 * Ver docs/matching.md.
 */
class MatchingCandidateLostEvent implements ShouldBroadcast
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
        return 'MatchingCandidateLostEvent';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
