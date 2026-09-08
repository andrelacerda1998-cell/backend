<?php

namespace App\Services\Common\Services;

use App\Enums\Services\ServiceStatus;
use App\Models\Service;

/**
 * Regra da falta do TÉCNICO a um serviço marcado.
 *
 * "Se o técnico faltar, em vez de receber o valor do serviço é penalizado em
 * 50% do que iria receber." — decisão do André.
 *
 * O que está em causa não é o dinheiro: é o cliente que ficou em casa à espera
 * e a marcação que não se conseguiu vender a mais ninguém. Por isso a conta faz-se
 * sobre `amount_for_vendor` (o que ELE ia receber) e não sobre o total pago pelo
 * cliente — penalizar sobre o total seria cobrar-lhe também a margem da Piquet.
 *
 * NÚCLEO PURO, como a CancellationPolicy: só decide SE penaliza e QUANTO, sem
 * tocar em carteiras nem na base de dados. A regra mexe em dinheiro de pessoas e
 * tem de ser testável sem gateway, sem BD e sem relógio.
 */
class VendorNoShowPolicy
{
    /**
     * Fração do que o técnico ia receber que lhe é cobrada por faltar.
     */
    public const PENALTY_RATIO = 0.5;

    /**
     * Estados em que ainda faz sentido declarar falta.
     *
     * SCHEDULED é o caso normal: passou a hora e ele nunca marcou "A caminho".
     * ACCEPTED entra porque aceitar e pôr-se a caminho não é aparecer — há quem
     * marque "A caminho" e nunca chegue, e para o cliente à porta isso é a mesma
     * falta. A partir de ARRIVED ele esteve lá: o que houver a resolver depois
     * disso é uma disputa sobre o trabalho, não uma falta.
     */
    public const OPEN_STATUSES = [
        ServiceStatus::SCHEDULED,
        ServiceStatus::ACCEPTED,
    ];

    /**
     * Pode declarar-se falta neste serviço?
     *
     * Um serviço já penalizado não volta a ser penalizado — a idempotência é o
     * que impede que duas pessoas do backoffice, ou um duplo clique, cobrem a
     * mesma falta duas vezes.
     */
    public static function isPenalizable(Service $service): bool
    {
        if ($service->vendor_no_show_at !== null) {
            return false;
        }

        if (! $service->vendor_id) {
            return false;
        }

        return in_array($service->status, self::OPEN_STATUSES, true);
    }

    /**
     * Valor da penalização, em cêntimos, a partir do que o técnico ia receber.
     *
     * Arredonda ao cêntimo. Um serviço sem valor para o técnico (ou negativo por
     * algum acerto anterior) não gera penalização: não há metade de nada, e
     * inventar um valor mínimo aqui seria uma regra que ninguém decidiu.
     */
    public static function penaltyAmount(int $amountForVendor): int
    {
        $amount = abs($amountForVendor);

        if ($amount <= 0) {
            return 0;
        }

        return (int) round($amount * self::PENALTY_RATIO);
    }
}
