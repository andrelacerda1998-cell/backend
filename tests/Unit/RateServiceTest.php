<?php

namespace Tests\Unit;

use App\Services\RateService;
use App\Settings\RateSettings;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression tests for the negative-commission bug.
 *
 * Root cause: `amount` (customer) and `amount_for_vendor` (vendor) were computed from
 * DIFFERENT distances (scheduled customer used the vendor's schedule address; the vendor
 * payout used the live GPS location). With price_rate = 0 the price is distance-only, so the
 * divergence pushed `amount_for_vendor` above `amount` -> commission = amount - amount_for_vendor
 * went negative (real case: customer 5,90 € / vendor 26,57 € / commission -20,67 €).
 *
 * The fix computes both values from the SAME distance. These tests pin the invariant that makes
 * that safe: for any single distance, the customer price is always >= the vendor payout.
 */
class RateServiceTest extends TestCase
{
    private RateService $rateService;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic time-of-day multiplier (daytime = 100 -> factor 1.0).
        Carbon::setTestNow(Carbon::create(2026, 7, 5, 12, 0, 0));

        config(['services.invoiceExpress.vat' => 23]); // VAT factor 1.23

        RateSettings::fake([
            'daytime' => 100,
            'evening' => 100,
            'night' => 100,
            'late_night' => 100,
            'midnight' => 100,
            'kilometer_price' => 50,
            'system_commission' => 20, // 20%
        ]);

        $this->rateService = app(RateService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Core invariant: measured at the SAME distance, the customer price is never below the
     * vendor payout, for both instant and scheduled services and across rates/distances.
     * This is what guarantees commission >= 0 once both values share one distance.
     */
    #[DataProvider('priceScenarios')]
    public function test_customer_price_is_never_below_vendor_payout(int $rate, int $time, int $distance): void
    {
        $vendor = $this->rateService->calculateForVendor($rate, $time, $distance);

        $customerInstant = $this->rateService->calculateForCustomerInstantService($rate, $time, $distance);
        $customerSchedule = $this->rateService->calculateForCustomerForSchedule($rate, $time, $distance);

        $this->assertGreaterThanOrEqual($vendor, $customerInstant, 'Instant customer price below vendor payout');
        $this->assertGreaterThanOrEqual($vendor, $customerSchedule, 'Scheduled customer price below vendor payout');
    }

    public static function priceScenarios(): array
    {
        return [
            'rate 0, distance-only, short'  => [0, 60, 9],
            'rate 0, distance-only, long'   => [0, 60, 51],
            'typical rate, mid distance'    => [1500, 60, 12],
            'high rate, short distance'     => [3000, 30, 3],
            'zero distance'                 => [1500, 60, 0],
        ];
    }

    /**
     * Reproduces the reported bug: when the customer is priced at a SMALL (schedule) distance
     * but the vendor is paid at a LARGE (GPS) distance, the vendor payout exceeds the customer
     * amount -> negative commission. The fix removes this by using one distance for both.
     */
    public function test_divergent_distances_reproduce_negative_commission(): void
    {
        // price_rate = 0 -> distance-only, like the real service.
        $customerAtScheduleDistance = $this->rateService->calculateForCustomerForSchedule(0, 60, 9);
        $vendorAtGpsDistance = $this->rateService->calculateForVendor(0, 60, 51);

        // Old (buggy) mix: vendor paid for 51 km, customer charged for 9 km.
        $this->assertGreaterThan(
            $customerAtScheduleDistance,
            $vendorAtGpsDistance,
            'Expected the divergent-distance mix to invert commission (documents the bug)'
        );

        // Fixed: both from the SAME distance -> commission stays >= 0.
        $customerSameDistance = $this->rateService->calculateForCustomerForSchedule(0, 60, 51);
        $vendorSameDistance = $this->rateService->calculateForVendor(0, 60, 51);
        $this->assertGreaterThanOrEqual(0, $customerSameDistance - $vendorSameDistance);
    }

    /**
     * The voucher cap (mirrors calculateTransaction): the discount can never push the amount
     * below the vendor payout. Verified against real RateService values, for every discount %.
     */
    #[DataProvider('discountPercentages')]
    public function test_voucher_cap_keeps_amount_at_or_above_vendor_payout(int $discountPercentage): void
    {
        $rate = 1500;
        $time = 60;
        $distance = 12;

        $originalAmount = (int) round($this->rateService->calculateForCustomerInstantService($rate, $time, $distance));
        $vendorAmount = (int) round($this->rateService->calculateForVendor($rate, $time, $distance));

        // Same formula as CalculateServicePriceForCustomer::calculateTransaction().
        $nominalDiscount = (int) round($originalAmount * ($discountPercentage / 100));
        $maxDiscount = max(0, $originalAmount - $vendorAmount);
        $discountAmount = min($nominalDiscount, $maxDiscount);
        $amount = $originalAmount - $discountAmount;

        $this->assertGreaterThanOrEqual($vendorAmount, $amount, "Discount {$discountPercentage}% pushed amount below vendor payout");
        $this->assertGreaterThanOrEqual(0, $amount - $vendorAmount, 'Commission went negative');
    }

    public static function discountPercentages(): array
    {
        return [[0], [10], [40], [90], [100]];
    }
}
