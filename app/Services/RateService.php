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

    /**
     * A parcela da deslocacao dentro do que o CLIENTE paga.
     *
     * O preco e uma soma — tempo + quilometros — que depois sobe pela comissao
     * da plataforma e pelo IVA. Mostrar so `kilometer_price x km` ao cliente
     * seria mentir-lhe por defeito: esse e o valor que chega ao profissional,
     * nao o que sai da carteira de quem paga.
     *
     * Por isso nao se repete a formula aqui. Corre-se a MESMA, com a tarifa e
     * o tempo a zero: sobra a parcela dos quilometros, ja com a comissao e o
     * IVA por cima. Se a formula mudar um dia, este numero acompanha sozinho e
     * continua a ser exatamente a fatia que esta dentro do total.
     *
     * A comissao horaria nao entra de proposito — multiplica o tempo de
     * trabalho, nunca a estrada.
     */
    public function calculateTravelForCustomer($distance, bool $isScheduled = false, $round = true, $addVat = true): float
    {
        return $isScheduled
            ? $this->calculateForCustomerForSchedule(0, 0, $distance, $round, $addVat)
            : $this->calculateForCustomerInstantService(0, 0, $distance, $round, $addVat);
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
