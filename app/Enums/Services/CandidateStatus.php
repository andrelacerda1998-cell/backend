<?php

namespace App\Enums\Services;

/**
 * Percurso de um candidato — ver docs/matching.md.
 */
enum CandidateStatus: string
{
    /**
     * @deprecated Legado do fluxo imediato antigo, em que o profissional entrava
     * na lista mostrada ao cliente sem ser incomodado. Desde a unificação dos
     * dois modos em difusão, ninguém entra neste estado — mas há linhas antigas
     * com ele, por isso o caso mantém-se para não rebentar a leitura delas.
     */
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
