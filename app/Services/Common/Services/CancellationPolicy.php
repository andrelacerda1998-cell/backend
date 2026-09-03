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
     * Escalões de penalização ao cancelar um serviço AGENDADO, do mais caro
     * para o mais barato: horas que faltam => fração cobrada.
     *
     * Quanto mais perto da hora marcada, mais caro: o técnico bloqueou o
     * horário e já não o consegue vender a outro cliente. Decisão do André.
     */
    public const SCHEDULED_PENALTY_TIERS = [
        1 => 1.0,    // menos de 1 hora   -> 100%
        6 => 0.75,   // menos de 6 horas  -> 75%
        12 => 0.5,   // menos de 12 horas -> 50%
    ];

    /**
     * Fração do valor a cobrar por cancelar AGORA um serviço agendado (0 a 1).
     *
     * Sem data utilizável devolve 0 — não se cobra por uma conta que não se
     * conseguiu fazer. Um agendamento cuja hora já passou fica no escalão
     * máximo: o técnico está à porta ou já lá esteve.
     */
    public static function scheduledPenaltyRatio(?\DateTimeInterface $scheduledAt, ?\DateTimeInterface $now = null): float
    {
        if ($scheduledAt === null) {
            return 0.0;
        }

        $now ??= now();
        $hoursLeft = ($scheduledAt->getTimestamp() - $now->getTimestamp()) / 3600;

        if ($hoursLeft <= 0) {
            return 1.0;
        }

        foreach (self::SCHEDULED_PENALTY_TIERS as $withinHours => $ratio) {
            if ($hoursLeft <= $withinHours) {
                return $ratio;
            }
        }

        return 0.0;
    }

    /** Valor a cobrar, em cêntimos, por cancelar agora (0 = cancelamento livre). */
    public static function scheduledPenaltyAmount(int $amount, float $ratio): int
    {
        if ($ratio <= 0) {
            return 0;
        }

        return (int) round(abs($amount) * min(1.0, $ratio));
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
