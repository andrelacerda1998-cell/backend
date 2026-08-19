<?php

namespace Tests\Unit;

use App\Models\GeneralSettings\ServicesType;
use App\Trait\Services\CalculateServicePriceForCustomer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A alavanca das unidades: effectiveMinutes = duração × quantidade.
 *
 * É a única multiplicação que faz "2 torneiras" custarem o dobro da mão de obra
 * sem duplicar a deslocação — todo o resto do preço deriva daqui. Testa-se o
 * método diretamente (é protected no trait, exposto aqui por uma subclasse
 * anónima) para o contrato ficar preso mesmo que os call-sites mudem.
 */
class EffectiveMinutesTest extends TestCase
{
    private function calculator(): object
    {
        return new class
        {
            use CalculateServicePriceForCustomer;

            public function minutes(ServicesType $type, int $quantity): int
            {
                return $this->effectiveMinutes($type, $quantity);
            }
        };
    }

    private function serviceType(int $time): ServicesType
    {
        $type = new ServicesType;
        $type->time = $time;

        return $type;
    }

    #[DataProvider('scenarios')]
    public function test_minutes_scale_with_quantity(int $time, int $quantity, int $expected): void
    {
        $this->assertSame($expected, $this->calculator()->minutes($this->serviceType($time), $quantity));
    }

    public static function scenarios(): array
    {
        return [
            'uma unidade = duração base' => [90, 1, 90],
            'duas unidades = dobro' => [90, 2, 180],
            'cinco unidades' => [30, 5, 150],
            'dez unidades' => [60, 10, 600],
        ];
    }

    /**
     * Guarda contra o zero e o negativo: uma quantidade inválida vinda de um
     * cliente adulterado nunca pode tornar o serviço grátis (minutos = 0) nem
     * negativo. Clampa a 1 — pior é cobrar uma unidade do que oferecer o serviço.
     */
    #[DataProvider('degenerate')]
    public function test_invalid_quantity_never_zeroes_the_price(int $quantity): void
    {
        $this->assertSame(60, $this->calculator()->minutes($this->serviceType(60), $quantity));
    }

    public static function degenerate(): array
    {
        return [[0], [-1], [-999]];
    }
}
