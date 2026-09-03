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

    /**
     * Penalização por cancelar um agendamento: 12h -> 50%, 6h -> 75%,
     * 1h -> 100%. Mais de 12h antes não custa nada.
     *
     * @dataProvider scheduledPenaltyScenarios
     */
    public function test_scheduled_penalty_ratio_by_tier(float $hoursLeft, float $expected): void
    {
        $now = \Carbon\Carbon::parse('2026-09-03 12:00:00');
        $scheduledAt = $now->copy()->addMinutes((int) round($hoursLeft * 60));

        $this->assertSame($expected, CancellationPolicy::scheduledPenaltyRatio($scheduledAt, $now));
    }

    public static function scheduledPenaltyScenarios(): array
    {
        return [
            'uma semana antes' => [168.0, 0.0],
            'dois dias antes' => [48.0, 0.0],
            'um dia antes' => [24.0, 0.0],
            'pouco mais de 12h' => [12.5, 0.0],
            'exatamente 12h' => [12.0, 0.5],
            'oito horas antes' => [8.0, 0.5],
            'pouco mais de 6h' => [6.5, 0.5],
            'exatamente 6h' => [6.0, 0.75],
            'duas horas antes' => [2.0, 0.75],
            'pouco mais de 1h' => [1.5, 0.75],
            'exatamente 1h' => [1.0, 1.0],
            'quinze minutos antes' => [0.25, 1.0],
            'à hora marcada' => [0.0, 1.0],
            'já passou' => [-3.0, 1.0],
        ];
    }

    public function test_scheduled_penalty_without_date_does_not_charge(): void
    {
        $this->assertSame(0.0, CancellationPolicy::scheduledPenaltyRatio(null));
    }

    public function test_scheduled_penalty_amount_applies_the_ratio(): void
    {
        $this->assertSame(3000, CancellationPolicy::scheduledPenaltyAmount(6000, 0.5));
        $this->assertSame(4500, CancellationPolicy::scheduledPenaltyAmount(6000, 0.75));
        $this->assertSame(6000, CancellationPolicy::scheduledPenaltyAmount(6000, 1.0));
        $this->assertSame(0, CancellationPolicy::scheduledPenaltyAmount(6000, 0.0));
    }

    public function test_scheduled_penalty_amount_rounds_and_normalizes(): void
    {
        // 25,25 a 50% dá 12,625 -> 12,63 ao cêntimo.
        $this->assertSame(1263, CancellationPolicy::scheduledPenaltyAmount(2525, 0.5));
        // Valores negativos vêm de payloads antigos; conta-se o módulo.
        $this->assertSame(3000, CancellationPolicy::scheduledPenaltyAmount(-6000, 0.5));
        // Nunca cobra mais do que o serviço, mesmo com um rácio disparatado.
        $this->assertSame(6000, CancellationPolicy::scheduledPenaltyAmount(6000, 2.0));
    }
}
