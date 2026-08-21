<?php

namespace Tests\Unit;

use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Services\Common\Services\CancellationPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regra de cobrança no cancelamento: 100% após "a caminho" ou "em execução",
 * repartido 50/50. Núcleo puro, sem BD nem pagamentos.
 */
class CancellationPolicyTest extends TestCase
{
    private function service(ServiceStatus $status, ?string $onTheWayAt): Service
    {
        $s = new Service;
        $s->status = $status;
        $s->on_the_way_at = $onTheWayAt;

        return $s;
    }

    public function test_arrived_is_always_chargeable(): void
    {
        $this->assertTrue(CancellationPolicy::isChargeable($this->service(ServiceStatus::ARRIVED, null)));
    }

    public function test_accepted_and_on_the_way_is_chargeable(): void
    {
        $this->assertTrue(CancellationPolicy::isChargeable($this->service(ServiceStatus::ACCEPTED, '2026-08-19 12:00:00')));
    }

    public function test_accepted_but_not_departed_is_not_chargeable(): void
    {
        // Aceite mas parado: ninguém se deslocou -> continua a reembolsar.
        $this->assertFalse(CancellationPolicy::isChargeable($this->service(ServiceStatus::ACCEPTED, null)));
    }

    #[DataProvider('nonChargeableStatuses')]
    public function test_early_statuses_never_charge(ServiceStatus $status): void
    {
        // Mesmo com on_the_way_at preenchido por engano, um estado inicial não cobra.
        $this->assertFalse(CancellationPolicy::isChargeable($this->service($status, '2026-08-19 12:00:00')));
    }

    public static function nonChargeableStatuses(): array
    {
        return [
            'pending' => [ServiceStatus::PENDING],
            'scheduled' => [ServiceStatus::SCHEDULED],
        ];
    }

    #[DataProvider('splitScenarios')]
    public function test_split_is_half_and_always_sums_to_amount(int $amount): void
    {
        $split = CancellationPolicy::split($amount);

        $this->assertSame($amount, $split['vendor'] + $split['platform'], 'A repartição não soma o total ao cêntimo');
        $this->assertSame((int) round($amount * 0.5), $split['vendor']);
        $this->assertGreaterThanOrEqual(0, $split['vendor']);
        $this->assertGreaterThanOrEqual(0, $split['platform']);
    }

    public static function splitScenarios(): array
    {
        return [
            'par' => [5000],   // 25,00 -> 2500 / 2500
            'ímpar (cent)' => [5001],   // arredonda: 2501 / 2500 (soma 5001)
            'ímpar baixo' => [3],      // 2 / 1
            'um cêntimo' => [1],      // 1 / 0
            'zero' => [0],
            'real 37,56' => [3756],
        ];
    }

    public function test_negative_amount_is_clamped_to_zero(): void
    {
        $split = CancellationPolicy::split(-100);
        $this->assertSame(0, $split['vendor']);
        $this->assertSame(0, $split['platform']);
    }
}
