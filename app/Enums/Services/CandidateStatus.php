<?php

namespace App\Enums\Services;

/**
 * Percurso de um candidato — ver docs/matching.md.
 *
 * SHORTLISTED existe só no fluxo imediato: o profissional entra na lista que o
 * cliente vê sem ser incomodado. É o que garante que ninguém aceita em vão.
 */
enum CandidateStatus: string
{
    /** Na lista mostrada ao cliente, ainda sem ser notificado (só no imediato). */
    case SHORTLISTED = 'shortlisted';

    /** Recebeu o pedido e a janela de resposta está a correr. */
    case NOTIFIED = 'notified';

    /** Disse que sim. Não é compromisso de agenda — só o escolhido reserva o slot. */
    case ACCEPTED = 'accepted';

    case DECLINED = 'declined';

    /** A janela fechou sem resposta. */
    case EXPIRED = 'expired';

    /** O cliente escolheu-o. */
    case SELECTED = 'selected';

    /** Aceitou mas o cliente escolheu outro, ou o pedido fechou antes. */
    case LOST = 'lost';

    /** Estados em que ainda pode vir a ficar com o serviço. */
    public function isLive(): bool
    {
        return in_array($this, [self::SHORTLISTED, self::NOTIFIED, self::ACCEPTED], true);
    }
}
