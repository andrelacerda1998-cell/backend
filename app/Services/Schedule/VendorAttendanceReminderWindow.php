<?php

namespace App\Services\Schedule;

use Carbon\CarbonInterface;

/**
 * Quando lembrar o tecnico de que tem um servico marcado.
 *
 * A 72h, com uma janela de 12h para o comando horario nao perder o momento por
 * causa de uma falha pontual. Nem mais cedo — a esta distancia ainda se pode
 * remarcar sem penalizacao para o cliente — nem mais tarde, que e quando ja nao
 * ha tempo de arranjar outro tecnico.
 *
 * Nucleo puro: e esta conta que decide quem recebe uma notificacao.
 */
class VendorAttendanceReminderWindow
{
    /** Abre a 72h do servico. */
    public const OPENS_HOURS_BEFORE = 72;

    /** E fecha a 60h: se o comando falhar durante meio dia, o aviso ainda sai. */
    public const CLOSES_HOURS_BEFORE = 60;

    public static function isOpen(CarbonInterface $startsAt, CarbonInterface $now): bool
    {
        if ($startsAt->lessThanOrEqualTo($now)) {
            return false;
        }

        $hoursAhead = $now->floatDiffInHours($startsAt);

        return $hoursAhead <= self::OPENS_HOURS_BEFORE
            && $hoursAhead >= self::CLOSES_HOURS_BEFORE;
    }
}
