<?php

namespace App\Events\Matching;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * O pedido fechou (preenchido ou falhado). Quem ainda não respondeu deixa de o ver — silêncio é o que destrói a confiança.
 *
 * Ver docs/matching.md.
 */
class MatchingRequestClosedEvent implements ShouldBroadcast
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
        return 'MatchingRequestClosedEvent';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
