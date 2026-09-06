<?php

namespace App\Enums\Schedule;

use Carbon\CarbonInterface;

/**
 * De quanto em quanto tempo um agendamento se repete.
 *
 * O cálculo da data seguinte vive aqui, e não espalhado por controladores: é
 * ele que decide quando o cliente volta a ser cobrado, e um erro de um dia numa
 * marcação mensal é um erro que ninguém apanha a olho.
 */
enum ScheduleRecurrence: string
{
    case WEEKLY = 'weekly';
    case BIWEEKLY = 'biweekly';
    case MONTHLY = 'monthly';

    /**
     * A data da ocorrência seguinte.
     *
     * Mensal usa addMonthNoOverflow: com addMonth, um serviço marcado a 31 de
     * janeiro saltava para 3 de março. Sem overflow fica no último dia do mês,
     * que é o que o cliente espera de "todos os meses nesta altura".
     */
    public function next(CarbonInterface $from): CarbonInterface
    {
        return match ($this) {
            self::WEEKLY => $from->copy()->addWeek(),
            self::BIWEEKLY => $from->copy()->addWeeks(2),
            self::MONTHLY => $from->copy()->addMonthNoOverflow(),
        };
    }

    /** As opções que a app pode enviar. */
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }
}
