<?php

namespace Tests\Unit;

use App\Services\Schedule\RecurrencePaymentReminderWindow as Window;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Janela do lembrete de pagamento (72h-48h antes). Nucleo puro: e esta conta
 * que decide se alguem recebe uma notificacao, e errar aqui ou enche o
 * telemovel do cliente ou deixa a marcacao cair sem aviso.
 */
class RecurrencePaymentReminderWindowTest extends TestCase
{
    private function at(string $time): Carbon
    {
        return Carbon::parse($time);
    }

    public function test_abre_exatamente_a_72_horas(): void
    {
        $this->assertTrue(Window::isOpen($this->at('2026-09-10 14:00'), $this->at('2026-09-07 14:00')));
    }

    public function test_fecha_exatamente_a_48_horas(): void
    {
        $this->assertTrue(Window::isOpen($this->at('2026-09-10 14:00'), $this->at('2026-09-08 14:00')));
    }

    public function test_esta_aberta_no_meio_da_janela(): void
    {
        $this->assertTrue(Window::isOpen($this->at('2026-09-10 14:00'), $this->at('2026-09-08 02:00')));
    }

    public function test_ainda_nao_abriu_a_quatro_dias(): void
    {
        $this->assertFalse(Window::isOpen($this->at('2026-09-10 14:00'), $this->at('2026-09-06 14:00')));
        $this->assertFalse(Window::hasPassed($this->at('2026-09-10 14:00'), $this->at('2026-09-06 14:00')));
    }

    public function test_ja_passou_abaixo_das_48_horas(): void
    {
        $now = $this->at('2026-09-09 00:00'); // 38h antes
        $this->assertFalse(Window::isOpen($this->at('2026-09-10 14:00'), $now));
        $this->assertTrue(Window::hasPassed($this->at('2026-09-10 14:00'), $now));
    }

    public function test_servico_ja_comecado_nao_tem_aviso_a_dar(): void
    {
        $this->assertFalse(Window::isOpen($this->at('2026-09-10 14:00'), $this->at('2026-09-10 14:00')));
        $this->assertTrue(Window::hasPassed($this->at('2026-09-10 14:00'), $this->at('2026-09-11 09:00')));
    }
}
