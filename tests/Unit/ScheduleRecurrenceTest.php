<?php

namespace Tests\Unit;

use App\Enums\Schedule\ScheduleRecurrence;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Data da ocorrencia seguinte. Nucleo puro, sem BD: e esta conta que decide
 * quando o cliente volta a ser marcado e cobrado.
 */
class ScheduleRecurrenceTest extends TestCase
{
    public function test_semanal_soma_sete_dias(): void
    {
        $next = ScheduleRecurrence::WEEKLY->next(Carbon::parse('2026-09-07'));

        $this->assertSame('2026-09-14', $next->toDateString());
    }

    public function test_quinzenal_soma_catorze_dias(): void
    {
        $next = ScheduleRecurrence::BIWEEKLY->next(Carbon::parse('2026-09-07'));

        $this->assertSame('2026-09-21', $next->toDateString());
    }

    public function test_mensal_mantem_o_dia_do_mes(): void
    {
        $next = ScheduleRecurrence::MONTHLY->next(Carbon::parse('2026-09-07'));

        $this->assertSame('2026-10-07', $next->toDateString());
    }

    public function test_mensal_nao_salta_para_o_mes_seguinte_em_dias_31(): void
    {
        // Com addMonth, 31 de janeiro ia parar a 3 de marco. O cliente que marca
        // no ultimo dia do mes espera o ultimo dia do mes seguinte.
        $next = ScheduleRecurrence::MONTHLY->next(Carbon::parse('2026-01-31'));

        $this->assertSame('2026-02-28', $next->toDateString());
    }

    public function test_nao_altera_a_data_original(): void
    {
        $original = Carbon::parse('2026-09-07');
        ScheduleRecurrence::WEEKLY->next($original);

        $this->assertSame('2026-09-07', $original->toDateString());
    }

    public function test_valores_aceites_sao_os_que_a_app_envia(): void
    {
        $this->assertSame(['weekly', 'biweekly', 'monthly'], ScheduleRecurrence::values());
    }
}
