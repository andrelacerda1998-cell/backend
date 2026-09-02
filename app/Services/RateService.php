<?php

namespace App\Services;

use App\Settings\RateSettings;
use Carbon\Carbon;

class RateService
{
    public function __construct(private RateSettings $rateSettings)
    {
    }

    private function calculateDistanceRate($distance): int
    {
        $kilometerPrice = $this->rateSettings->kilometer_price;

        return $kilometerPrice * $distance;
    }

    private function calculateTimeRate($hourRate, $timeService): int
    {
        $time = $timeService / 60;

        return $hourRate * $time;
    }

    public function calculateForCustomerForSchedule($hourRate, $timeService, $distance, $round = true, $addVat = true): float
    {
        $total = $this->calculateForCustomerWithoutDiscount($hourRate, $timeService, $distance, false, false);

        if ($addVat) {
            $total = $total * $this->getVat();
        }

        if ($round)
            return round($total);
        else
            return $total;
    }

    public function calculateForCustomerForOldPrice($hourRate, $timeService, $distance, $round = true, $addVat = true): float
    {
        $systemCommission = $this->calculateSystemCommissionRate();

        $vendorSubtotal = $this->calculateForVendor($hourRate, $timeService, $distance, false, false);
        $total = (($vendorSubtotal) / 0.75) / (1 - $systemCommission);

        if ($addVat) {
            $total = $total * $this->getVat();
        }

        if ($round)
            return round($total);
        else
            return $total;
    }

    public function calculateForCustomerWithoutDiscount($hourRate, $timeService, $distance, $round = true, $addVat = true): float
    {
        $systemCommission = $this->calculateSystemCommissionRate();

        $vendorSubtotal = $this->calculateForVendor($hourRate, $timeService, $distance, false, false);
        $total = ($vendorSubtotal) / (1 - $systemCommission);

        if ($addVat) {
            $total = $total * $this->getVat();
        }

        if ($round)
            return round($total);
        else
            return $total;
    }

    public function calculateForCustomerInstantService($hourRate, $timeService, $distance, $round = true, $addVat = true): float
    {
        return $this->calculateForCustomerForOldPrice($hourRate, $timeService, $distance, $round, $addVat);
    }

    public function calculateForVendor($hourRate, $timeService, $distance, $round = true, $addVat = true): float
    {
        $distanceRate = $this->calculateDistanceRate($distance);
        $timeRate = $this->calculateTimeRate($hourRate, $timeService);
        $hourCommission = $this->calculateHourCommission();

        $total = (($timeRate * $hourCommission) + $distanceRate);

        if ($addVat) {
            $total = $total * $this->getVat();
        }

        if ($round)
            return round($total);
        else
            return $total;
    }

    public function calculateSystemFee($hourRate, $timeService, $distance): float
    {
        return $this->calculateForCustomerForSchedule($hourRate, $timeService, $distance, false, false)
            - $this->calculateForVendor($hourRate, $timeService, $distance, false, false);
    }

    /**
     * Quanto do preço do cliente é só a deslocação — para mostrar em separado
     * do trabalho, e não só o km em bruto.
     *
     * Não reimplementa a fórmula: chama a MESMA função pública que calcula o
     * preço todo, só que com tempo de serviço zero. Como a comissão e o IVA
     * são fatores lineares aplicados à soma (tempo + distância), zerar o
     * tempo isola exatamente a parcela da distância depois de comissão e IVA
     * — e a parcela do trabalho (mesma chamada com distance=0) soma de volta
     * ao preço total, sem arredondamentos a mais nem fórmula duplicada que
     * possa um dia divergir da fórmula real.
     */
    public function calculateForCustomerTravelPortion($hourRate, $distance, bool $isScheduled, $round = true, $addVat = true): float
    {
        return $isScheduled
            ? $this->calculateForCustomerForSchedule($hourRate, 0, $distance, $round, $addVat)
            : $this->calculateForCustomerInstantService($hourRate, 0, $distance, $round, $addVat);
    }

    private function calculateHourCommission(): float|int
    {
        $now = Carbon::now();
        $hour = $now->hour;
        $commission = match (true) {
            $hour >=  8 && $hour <= 17 => $this->rateSettings->daytime,
            $hour >= 18 && $hour <= 20 => $this->rateSettings->evening,
            $hour >= 21 && $hour <= 23 => $this->rateSettings->night,
            $hour >=  0 && $hour <=  2 => $this->rateSettings->late_night,
            $hour >=  3 && $hour <=  7 => $this->rateSettings->midnight,
            default => 1,
        };

        return $commission/100;
    }

    private function calculateSystemCommissionRate(): float
    {
        return $this->rateSettings->system_commission / 100;
    }

    private function getVat()
    {
        $vat = config('services.invoiceExpress.vat');
        return 1+($vat/100);
    }
}
