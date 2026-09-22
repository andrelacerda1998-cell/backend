<?php

namespace App\Services;

use App\Settings\RateSettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class RateService
{
    /**
     * As faixas horarias sao horas de Portugal, nao horas UTC.
     *
     * O APP_TIMEZONE e UTC, e todo o resto do sistema que decide pela hora do
     * dia — lembretes de agenda, deteccao de no-show, filtro de slots — ja le
     * em Europe/Lisbon por causa do desvio WET/WEST. So o preco e que nao lia,
     * e em horario de verao isso desviava a tabela inteira uma hora: um
     * servico as 08:00 de Lisboa era lido como 07:00 UTC e cobrado a faixa da
     * madrugada (x1,90) em vez da diurna (x1,00).
     */
    private const FUSO_DO_NEGOCIO = 'Europe/Lisbon';

    public function __construct(private RateSettings $rateSettings) {}

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

    public function calculateForCustomerForSchedule($hourRate, $timeService, $distance, $round = true, $addVat = true, ?CarbonInterface $serviceAt = null): float
    {
        $total = $this->calculateForCustomerWithoutDiscount($hourRate, $timeService, $distance, false, false, $serviceAt);

        if ($addVat) {
            $total = $total * $this->getVat();
        }

        if ($round) {
            return round($total);
        } else {
            return $total;
        }
    }

    public function calculateForCustomerForOldPrice($hourRate, $timeService, $distance, $round = true, $addVat = true, ?CarbonInterface $serviceAt = null): float
    {
        $systemCommission = $this->calculateSystemCommissionRate();

        $vendorSubtotal = $this->calculateForVendor($hourRate, $timeService, $distance, false, false, $serviceAt);
        $total = (($vendorSubtotal) / 0.75) / (1 - $systemCommission);

        if ($addVat) {
            $total = $total * $this->getVat();
        }

        if ($round) {
            return round($total);
        } else {
            return $total;
        }
    }

    public function calculateForCustomerWithoutDiscount($hourRate, $timeService, $distance, $round = true, $addVat = true, ?CarbonInterface $serviceAt = null): float
    {
        $systemCommission = $this->calculateSystemCommissionRate();

        $vendorSubtotal = $this->calculateForVendor($hourRate, $timeService, $distance, false, false, $serviceAt);
        $total = ($vendorSubtotal) / (1 - $systemCommission);

        if ($addVat) {
            $total = $total * $this->getVat();
        }

        if ($round) {
            return round($total);
        } else {
            return $total;
        }
    }

    public function calculateForCustomerInstantService($hourRate, $timeService, $distance, $round = true, $addVat = true, ?CarbonInterface $serviceAt = null): float
    {
        return $this->calculateForCustomerForOldPrice($hourRate, $timeService, $distance, $round, $addVat, $serviceAt);
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
     * trabalho, nunca a estrada. E por isso que este metodo nao recebe
     * `$serviceAt` e nao e esquecimento: com o tempo a zero a parcela do
     * tempo e zero, e zero vezes o multiplicador continua zero. A deslocacao
     * custa o mesmo as 10:00 e as 03:00, e um parametro que nao muda nada
     * so convidava alguem a pensar que mudava.
     */
    public function calculateTravelForCustomer($distance, bool $isScheduled = false, $round = true, $addVat = true): float
    {
        return $isScheduled
            ? $this->calculateForCustomerForSchedule(0, 0, $distance, $round, $addVat)
            : $this->calculateForCustomerInstantService(0, 0, $distance, $round, $addVat);
    }

    public function calculateForVendor($hourRate, $timeService, $distance, $round = true, $addVat = true, ?CarbonInterface $serviceAt = null): float
    {
        $distanceRate = $this->calculateDistanceRate($distance);
        $timeRate = $this->calculateTimeRate($hourRate, $timeService);
        $hourCommission = $this->calculateHourCommission($serviceAt);

        $total = (($timeRate * $hourCommission) + $distanceRate);

        if ($addVat) {
            $total = $total * $this->getVat();
        }

        if ($round) {
            return round($total);
        } else {
            return $total;
        }
    }

    public function calculateSystemFee($hourRate, $timeService, $distance, ?CarbonInterface $serviceAt = null): float
    {
        return $this->calculateForCustomerForSchedule($hourRate, $timeService, $distance, false, false, $serviceAt)
            - $this->calculateForVendor($hourRate, $timeService, $distance, false, false, $serviceAt);
    }

    /**
     * O multiplicador da faixa horaria, lido na hora a que o trabalho vai ser
     * FEITO — nao na hora em que alguem pediu a conta.
     *
     * Enquanto isto lia `Carbon::now()`, quem marcasse as 22:00 um servico
     * para as 10:00 do dia seguinte pagava a faixa da noite: o preco dependia
     * do momento do checkout e nao do servico comprado. Dois clientes com a
     * mesma marcacao pagavam valores diferentes por terem carregado no botao
     * a horas diferentes.
     *
     * `$serviceAt` nulo continua a significar "agora" — e o que um pedido
     * imediato quer dizer, porque nesse caso o trabalho comeca agora.
     */
    private function calculateHourCommission(?CarbonInterface $serviceAt = null): float|int
    {
        $hour = ($serviceAt ?? Carbon::now())->copy()->setTimezone(self::FUSO_DO_NEGOCIO)->hour;
        $commission = match (true) {
            $hour >= 8 && $hour <= 17 => $this->rateSettings->daytime,
            $hour >= 18 && $hour <= 20 => $this->rateSettings->evening,
            $hour >= 21 && $hour <= 23 => $this->rateSettings->night,
            $hour >= 0 && $hour <= 2 => $this->rateSettings->late_night,
            $hour >= 3 && $hour <= 7 => $this->rateSettings->midnight,
            default => 1,
        };

        return $commission / 100;
    }

    private function calculateSystemCommissionRate(): float
    {
        return $this->rateSettings->system_commission / 100;
    }

    private function getVat()
    {
        $vat = config('services.invoiceExpress.vat');

        return 1 + ($vat / 100);
    }
}
