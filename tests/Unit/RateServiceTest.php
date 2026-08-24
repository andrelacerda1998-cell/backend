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
            'rate 0, distance-only, short' => [0, 60, 9],
            'rate 0, distance-only, long' => [0, 60, 51],
            'typical rate, mid distance' => [1500, 60, 12],
            'high rate, short distance' => [3000, 30, 3],
            'zero distance' => [1500, 60, 0],
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

    /**
     * ------------------------------------------------------------------
     * Unidades ("2 reparações de torneira"): mão de obra ×N, deslocação ×1.
     * ------------------------------------------------------------------
     *
     * A regra vive em CalculateServicePriceForCustomer::effectiveMinutes(), que
     * multiplica SÓ os minutos (tempo × unidades) e deixa a distância intacta.
     * Como o RateService recebe esses minutos já multiplicados, os testes abaixo
     * validam-no à porta do RateService: passar `time * quantity` tem de escalar
     * a mão de obra sem tocar na deslocação.
     */

    /**
     * O preço do cliente é AFIM no tempo: P(t) = mão_de_obra·t + deslocação.
     * Logo, para N unidades (t = T·N), a parcela de deslocação aparece uma só
     * vez. A prova sem depender das constantes: P(2T) = 2·P(T) − P(0), onde
     * P(0) é a deslocação pura (tempo zero). Se a deslocação fosse cobrada duas
     * vezes, esta igualdade partia-se.
     *
     * Sem arredondamento (round=false) para a igualdade ser exata.
     */
    #[DataProvider('quantityScenarios')]
    public function test_quantity_scales_labour_but_not_travel(int $rate, int $time, int $distance): void
    {
        $one = fn (int $t) => $this->rateService->calculateForCustomerInstantService($rate, $t, $distance, false);

        $priceOneUnit = $one($time);        // P(T)
        $priceTwoUnits = $one($time * 2);   // P(2T)
        $travelOnly = $one(0);              // P(0) = deslocação pura

        // A 2.ª unidade custa exatamente a mão de obra de uma unidade — e nada
        // de deslocação. É isto que "×N na mão de obra, ×1 na deslocação" quer dizer.
        $this->assertEqualsWithDelta(
            2 * $priceOneUnit - $travelOnly,
            $priceTwoUnits,
            0.0001,
            'Duas unidades cobraram a deslocação a dobrar'
        );

        // A parcela de deslocação é a mesma esteja em 1 ou em 5 unidades.
        $marginalSecond = $one($time * 2) - $one($time);
        $marginalFifth = $one($time * 5) - $one($time * 4);
        $this->assertEqualsWithDelta($marginalSecond, $marginalFifth, 0.0001, 'O custo marginal por unidade não é constante');
    }

    public static function quantityScenarios(): array
    {
        return [
            'rate típica, distância média' => [1500, 90, 12],
            'rate alta, curta' => [3000, 30, 3],
            'distância zero' => [1500, 60, 0],
            'distância longa' => [1200, 60, 51],
        ];
    }

    /**
     * O mesmo tem de valer para o AGENDADO e para o pagamento ao TÉCNICO: se só
     * a mão de obra escala num deles e a deslocação escala noutro, o cliente e o
     * técnico deixam de estar sincronizados e a comissão descola.
     */
    public function test_quantity_scaling_is_consistent_across_customer_and_vendor(): void
    {
        $rate = 1800;
        $time = 60;
        $distance = 20;

        foreach ([2, 3, 5] as $q) {
            $customerTravel = $this->rateService->calculateForCustomerForSchedule($rate, 0, $distance, false);
            $customer1 = $this->rateService->calculateForCustomerForSchedule($rate, $time, $distance, false);
            $customerN = $this->rateService->calculateForCustomerForSchedule($rate, $time * $q, $distance, false);
            $this->assertEqualsWithDelta($q * $customer1 - ($q - 1) * $customerTravel, $customerN, 0.0001, "Cliente agendado x{$q} inconsistente");

            $vendorTravel = $this->rateService->calculateForVendor($rate, 0, $distance, false);
            $vendor1 = $this->rateService->calculateForVendor($rate, $time, $distance, false);
            $vendorN = $this->rateService->calculateForVendor($rate, $time * $q, $distance, false);
            $this->assertEqualsWithDelta($q * $vendor1 - ($q - 1) * $vendorTravel, $vendorN, 0.0001, "Técnico x{$q} inconsistente");
        }
    }

    /**
     * A comissão (cliente − técnico) nunca fica negativa quando as unidades
     * sobem — é a mesma garantia do teste da distância única, agora sob N.
     */
    #[DataProvider('quantityCounts')]
    public function test_commission_stays_non_negative_across_quantities(int $quantity): void
    {
        $rate = 1500;
        $time = 60;
        $distance = 12;
        $minutes = $time * $quantity;

        $customer = (int) round($this->rateService->calculateForCustomerInstantService($rate, $minutes, $distance));
        $vendor = (int) round($this->rateService->calculateForVendor($rate, $minutes, $distance));

        $this->assertGreaterThanOrEqual($vendor, $customer, "Comissão negativa com {$quantity} unidades");
    }

    public static function quantityCounts(): array
    {
        return [[1], [2], [3], [5], [10]];
    }
}
