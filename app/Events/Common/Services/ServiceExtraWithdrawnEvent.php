<?php

namespace App\Events\Common\Services;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * O técnico retirou um pedido de extra ainda pendente (ex.: já não precisa).
 * Sem isto, um pedido que o cliente ainda não respondeu fica "pendente" no
 * ecrã dele até ele voltar a abrir a app — a app tem de saber em tempo real
 * que já não há nada para decidir.
 */
class ServiceExtraWithdrawnEvent implements ShouldBroadcast
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
        return 'ServiceExtraWithdrawnEvent';
    }
}
