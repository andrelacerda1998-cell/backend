<?php

namespace App\Events\Matching;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * O profissional escolhido não respondeu (ou recusou) e passámos ao seguinte.
 *
 * Vai para o canal do CLIENTE porque é ele que está no ecrã de espera. Sem
 * isto, o ecrã fica parado a dizer "a contactar o João" enquanto já se está a
 * contactar outra pessoa — e passados dois minutos o cliente desiste, achando
 * que ninguém lhe responde.
 *
 * Ver docs/matching.md.
 */
class MatchingFallbackEvent implements ShouldBroadcast
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
        return 'MatchingFallbackEvent';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
