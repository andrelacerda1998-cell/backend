<?php

namespace App\Events\Matching;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * O cliente escolheu-o e ainda não pagou.
 *
 * Ainda não é ganhar: o trabalho só é dele quando o pagamento entra
 * (`ServiceWonNotification`). Mas é a decisão que o ecrã do "ficaste na
 * lista" promete avisar, e até aqui não chegava nada.
 *
 * Ver docs/matching.md.
 */
class MatchingCandidateSelectedEvent implements ShouldBroadcast
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
        return 'MatchingCandidateSelectedEvent';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
