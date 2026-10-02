<?php

namespace App\Services\Payments;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Quanto tempo o dinheiro do cliente fica cativo, e até quando se pode agendar.
 *
 * A ordem de pagamento de um serviço é criada DIFERIDA (`OperationType::DEFERRED`):
 * autoriza-se agora e captura-se no fecho. Essa autorização tem prazo — o
 * `expires_in` que vai para o Payshop — e depois dele o `confirm()` falha, o
 * serviço fica em CLOSED_PENDING_PAYMENT e o técnico fez o trabalho sem receber.
 *
 * O número vivia escrito à mão em cinco sítios (três no
 * `ProcessesServicePayment`, dois no `ChargeServiceExtra`) e em nenhum deles
 * estava ligado ao limite do agendamento — que simplesmente não existia. Dava
 * para marcar um serviço para dentro de dois meses com uma cativação de 15
 * dias. Fica aqui, numa classe só, pela mesma razão por que a janela de
 * resposta do técnico foi para as definições: um número de dinheiro repetido é
 * um número que um dia se desalinha.
 */
final class JanelaDeCativacao
{
    /** Dias que a autorização do Payshop se mantém capturável. */
    public const DIAS = 15;

    /**
     * Dias de antecedência máxima para agendar: 14. Decisão do André, 02/10/2026.
     *
     * É UM DIA MENOS do que a cativação dura, e isso não é margem a mais -- é a
     * conta certa. A captura acontece no FECHO, depois do trabalho feito: um
     * slot às 18:00 do 15.º dia, marcado às 10:00 do dia zero, fecha-se para lá
     * das 360 horas e a autorização expirou oito horas antes.
     *
     * Derivado de `DIAS` e não escrito a 14: se o prazo do Payshop mudar, o
     * limite acompanha sem ninguém se lembrar dele.
     */
    public const DIAS_AGENDAVEIS = self::DIAS - 1;

    /** Quando expira uma cativação criada agora. */
    public static function expiraEm(?DateTimeInterface $de = null): Carbon
    {
        return Carbon::instance($de ? Carbon::instance($de) : Carbon::now())->addDays(self::DIAS);
    }

    /**
     * ÚLTIMO DIA QUE SE PODE AGENDAR — e é um dia ANTES do fim da cativação,
     * não o próprio.
     *
     * A captura não acontece à hora do serviço: acontece no FECHO, depois de o
     * trabalho estar feito. Um slot às 18:00 do 15.º dia, marcado às 10:00 do
     * dia zero, fecha-se já para lá das 360 horas — a cativação expirou oito
     * horas antes.
     *
     * Cortar no dia anterior cobre qualquer hora de qualquer slot sem ter de
     * conhecer o horário: o pior caso (marcar à meia-noite e um minuto, fechar
     * às 23:59 do 14.º dia) dá 14 dias e 23 horas, dentro dos 15.
     */
    public static function ultimoDiaAgendavel(?DateTimeInterface $de = null): CarbonImmutable
    {
        $agora = $de ? CarbonImmutable::instance($de) : CarbonImmutable::now();

        return $agora->addDays(self::DIAS_AGENDAVEIS)->endOfDay();
    }

    /** O fecho de um serviço nesta data e hora ainda cai dentro da cativação? */
    public static function cobre(DateTimeInterface $quando, ?DateTimeInterface $de = null): bool
    {
        return Carbon::instance($quando)->lessThanOrEqualTo(self::expiraEm($de));
    }
}
