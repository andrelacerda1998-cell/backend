<?php

namespace App\Services\Common\Services;

use App\Enums\Services\ServiceStatus;
use App\Models\Service;

/**
 * Regra de cobrança no cancelamento pelo cliente.
 *
 * "Depois de o técnico estar a caminho ou a executar, cancelar cobra 100%."
 *
 * NÚCLEO PURO de propósito: só decide SE cobra e COMO se reparte, sem tocar em
 * pagamentos nem na base de dados. Assim a regra — que mexe em dinheiro — fica
 * testável de forma isolada, e o mecanismo de captura/pagamento (frágil, ligado
 * ao Payshop) apoia-se nesta decisão em vez de a repetir.
 */
class CancellationPolicy
{
    /**
     * Fração do valor que fica para o TÉCNICO num cancelamento cobrado.
     * 50/50 com a plataforma — decisão do André, diferente do 75/25 do serviço
     * cumprido (aqui não houve trabalho feito, só deslocação a caminho).
     */
    public const VENDOR_SHARE = 0.5;

    /**
     * Cancelar AGORA implica cobrança de 100%?
     *
     * Sim quando o técnico já se pôs a caminho (on_the_way_at preenchido) ou já
     * está no local (ARRIVED). Aceite mas ainda parado (sem on_the_way_at) NÃO
     * cobra — ninguém se deslocou, e "a caminho" é à letra o gatilho.
     */
    public static function isChargeable(Service $service): bool
    {
        if ($service->status === ServiceStatus::ARRIVED) {
            return true;
        }

        if ($service->status === ServiceStatus::ACCEPTED && $service->on_the_way_at !== null) {
            return true;
        }

        return false;
    }

    /**
     * Reparte o valor COBRADO (em cêntimos) entre técnico e plataforma.
     *
     * O técnico leva metade (arredondada); a plataforma leva o RESTO, e não a
     * outra metade arredondada à parte — assim vendor + platform == amount ao
     * cêntimo, sempre. Somarem 1 cêntimo a mais ou a menos seria dinheiro criado
     * ou perdido no acerto.
     *
     * @return array{vendor: int, platform: int}
     */
    public static function split(int $amount): array
    {
        $amount = max(0, $amount);
        $vendor = (int) round($amount * self::VENDOR_SHARE);

        return [
            'vendor' => $vendor,
            'platform' => $amount - $vendor,
        ];
    }
}
