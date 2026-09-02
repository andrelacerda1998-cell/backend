<?php

namespace Tests\Unit\Orders;

use App\Services\Orders\OrderTotals;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderTotalsTest extends TestCase
{
    private function lines(): array
    {
        return [
            ['customer_amount' => 3756, 'vendor_amount' => 2817],
            ['customer_amount' => 5904, 'vendor_amount' => 4428],
            ['customer_amount' => 2000, 'vendor_amount' => 1500],
        ];
    }

    public function test_customer_pays_the_sum_once(): void
    {
        $this->assertSame(3756 + 5904 + 2000, OrderTotals::customerTotal($this->lines()));
    }

    public function test_vendor_total_is_the_sum_of_each_share(): void
    {
        $this->assertSame(2817 + 4428 + 1500, OrderTotals::vendorTotal($this->lines()));
    }

    public function test_platform_is_customer_minus_vendors(): void
    {
        $lines = $this->lines();
        $this->assertSame(
            OrderTotals::customerTotal($lines) - OrderTotals::vendorTotal($lines),
            OrderTotals::platformTotal($lines)
        );
    }

    public function test_platform_never_goes_negative_on_bad_data(): void
    {
        $bad = [['customer_amount' => 1000, 'vendor_amount' => 9999]];
        $this->assertSame(0, OrderTotals::platformTotal($bad));
    }

    public function test_empty_order_is_all_zeros(): void
    {
        $this->assertSame(0, OrderTotals::customerTotal([]));
        $this->assertSame(0, OrderTotals::vendorTotal([]));
        $this->assertSame(0, OrderTotals::platformTotal([]));
    }

    #[DataProvider('discountScenarios')]
    public function test_discount_distributes_without_creating_or_losing_cents(int $discount): void
    {
        $parts = OrderTotals::distributeDiscount($this->lines(), $discount);
        $this->assertSame($discount, array_sum($parts), "Distribuição de {$discount} não soma o total");
        $this->assertCount(count($this->lines()), $parts);
        foreach ($parts as $p) {
            $this->assertGreaterThanOrEqual(0, $p);
        }
    }

    public static function discountScenarios(): array
    {
        return [
            'zero' => [0],
            'redondo' => [1000],
            'impar (resto)' => [1001],
            'total todo' => [11660],
            'um centimo' => [1],
        ];
    }

    public function test_discount_is_proportional_to_service_weight(): void
    {
        $lines = [
            ['customer_amount' => 5000, 'vendor_amount' => 0],
            ['customer_amount' => 5000, 'vendor_amount' => 0],
        ];
        $this->assertSame([500, 500], OrderTotals::distributeDiscount($lines, 1000));
    }

    public function test_no_discount_when_order_is_free(): void
    {
        $lines = [['customer_amount' => 0, 'vendor_amount' => 0]];
        $this->assertSame([0], OrderTotals::distributeDiscount($lines, 500));
    }
}
