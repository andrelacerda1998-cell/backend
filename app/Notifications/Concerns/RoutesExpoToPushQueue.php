<?php

namespace App\Notifications\Concerns;

/**
 * Encaminha o canal de push (expo) para a queue "push", que tem retry com
 * backoff (ver config/horizon.php). Os erros de push são quase sempre
 * transitórios (rede, Expo 5xx) e, com tries=1 na queue default, perdiam-se
 * sem nova tentativa — o defeito do incidente 13/08.
 *
 * Só o expo é reencaminhado: o canal "database" é escrita local fiável e fica
 * na queue default. Como o Laravel enfileira um job POR canal, um retry do
 * expo nunca reprocessa nem duplica a notificação na base de dados.
 */
trait RoutesExpoToPushQueue
{
    /**
     * @return array<string, string> canal => queue
     */
    public function viaQueues(): array
    {
        return ['expo' => 'push'];
    }
}
