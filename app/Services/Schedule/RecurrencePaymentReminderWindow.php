<?php

namespace App\Services\Schedule;

use Carbon\CarbonInterface;

/**
 * Quando avisar o cliente de que a proxima ocorrencia da serie precisa de ser
 * confirmada e paga.
 *
 * A janela e de 72h a 48h antes do servico. Nem antes -- um aviso a uma semana
 * de distancia e esquecido -- nem depois: abaixo das 48h ja nao ha folga para
 * o cliente reagir, o tecnico reservou o horario e cancelar entra nos escaloes
 * de penalizacao (12h/6h/1h, ver CancellationPolicy).
 *
 * Nucleo puro, sem BD: e esta conta que decide se uma pessoa recebe (ou nao)
 * uma notificacao, e um erro aqui ou enche o telemovel do cliente ou deixa a
 * marcacao cair sem aviso nenhum.
 */
class RecurrencePaymentReminderWindow
{
    /** Limite superior da janela: nao avisar mais cedo do que isto. */
    public const OPENS_HOURS_BEFORE = 72;

    /** Limite inferior: abaixo disto ja nao ha folga util para o cliente. */
    public const CLOSES_HOURS_BEFORE = 48;

    /**
     * Esta marcacao esta dentro da janela de aviso?
     *
     * @param  CarbonInterface  $startsAt  inicio do servico
     * @param  CarbonInterface  $now  momento da verificacao
     */
    public static function isOpen(CarbonInterface $startsAt, CarbonInterface $now): bool
    {
        // Servico que ja comecou (ou passou) nao tem aviso a dar.
        if ($startsAt->lessThanOrEqualTo($now)) {
            return false;
        }

        $hoursAhead = $now->floatDiffInHours($startsAt);

        return $hoursAhead <= self::OPENS_HOURS_BEFORE
            && $hoursAhead >= self::CLOSES_HOURS_BEFORE;
    }

    /**
     * Ja passou a janela sem aviso enviado?
     *
     * Serve para o comando distinguir "ainda nao chegou a altura" de "perdeu-se
     * a janela" -- o segundo caso e um aviso que ninguem recebeu e vale a pena
     * enviar na mesma, atrasado, em vez de deixar a marcacao cair calada.
     */
    public static function hasPassed(CarbonInterface $startsAt, CarbonInterface $now): bool
    {
        if ($startsAt->lessThanOrEqualTo($now)) {
            return true;
        }

        return $now->floatDiffInHours($startsAt) < self::CLOSES_HOURS_BEFORE;
    }
}
