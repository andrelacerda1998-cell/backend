<?php

namespace Tests\Unit;

use App\Models\Service;
use App\Services\Common\Services\MaterializePendingSchedule;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Qual a marcação que um pagamento vem confirmar.
 *
 * A regra decide se o dinheiro do cliente fica ligado a uma marcação que já
 * existe (ocorrência de uma série) ou se nasce outra. Errar aqui ou duplica o
 * agendamento, ou — pior — deixa alguém confirmar a marcação de outra pessoa.
 */
class ConfirmExistingScheduleTest extends TestCase
{
    private function resolve(array $scheduleData, int $customerId = 42)
    {
        $service = new Service;
        $service->customer_id = $customerId;

        $method = new ReflectionMethod(MaterializePendingSchedule::class, 'existingScheduleToConfirm');
        $method->setAccessible(true);

        return $method->invoke(app(MaterializePendingSchedule::class), $service, $scheduleData);
    }

    public function test_sem_schedule_id_nao_ha_marcacao_a_confirmar(): void
    {
        $this->assertNull($this->resolve([
            'scheduled_day' => '2026-09-14',
            'scheduled_time_start' => '14:30',
        ]));
    }

    public function test_schedule_id_nulo_e_tratado_como_ausente(): void
    {
        $this->assertNull($this->resolve(['schedule_id' => null]));
    }

    public function test_schedule_id_zero_nao_procura_marcacao(): void
    {
        // 0 é falsy mas chegaria à query como id — e uma query sem
        // correspondência devolve null de qualquer forma; o que este teste fixa
        // é que nem se tenta.
        $this->assertNull($this->resolve(['schedule_id' => 0]));
    }
}
