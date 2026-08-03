<?php

namespace App\Events\Common\Services;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * O técnico pediu tempo extra ou uma peça/material durante o serviço.
 * Entrega em tempo real ao cliente — a app abre o ecrã de aprovar/recusar
 * de imediato, onde quer que o cliente esteja, enquanto o técnico espera
 * no local. Complementa (não substitui) a notificação push, que continua a
 * cobrir a app em background/fechada.
 */
class ServiceExtraRequestedEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Mesma forma devolvida pelo endpoint (ver ServiceExtrasController::present). */
    public array $extra;

    private int $serviceId;

    public function __construct(int $serviceId, array $extra)
    {
        $this->serviceId = $serviceId;
        $this->extra = $extra;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('common.services.'.$this->serviceId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ServiceExtraRequestedEvent';
    }
}
