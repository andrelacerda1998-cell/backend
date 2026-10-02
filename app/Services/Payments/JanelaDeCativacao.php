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
     * Dias de antecedência máxima para agendar: 7. Decisão do André, 02/10/2026.
     *
     * NÃO é derivado de `DIAS`: é uma decisão de produto que por acaso cabe
     * folgadamente dentro da cativação. Chegou a ser `DIAS - 1` (14), a conta
     * mais permissiva que o dinheiro consegue suportar -- o limite da máquina.
     * Sete dias é mais apertado do que isso de propósito, e por razões que não
     * são técnicas: a semana é o horizonte em que uma pessoa sabe o que vai
     * fazer, e o técnico não fica com a agenda hipotecada a quinze dias.
     *
     * A folga é o que protege o resto. A captura acontece no FECHO, depois do
     * trabalho feito, e com sete dias o pior caso (marcar à meia-noite e um
     * minuto, fechar às 23:59 do 7.º dia) fica a mais de sete dias do fim da
     * autorização. Também dá espaço a um agendado pago por MBWay, onde não está
     * provado que o Payshop honre uma captura diferida tão longe.
     *
     * TEM DE SER MENOR QUE `DIAS`, e o `LimiteDeAgendamentoTest` guarda isso:
     * agendar para além da cativação é prometer um serviço que ninguém consegue
     * cobrar.
     */
    public const DIAS_AGENDAVEIS = 7;

    /** Quando expira uma cativação criada agora. */
    public static function expiraEm(?DateTimeInterface $de = null): Carbon
    {
        return Carbon::instance($de ? Carbon::instance($de) : Carbon::now())->addDays(self::DIAS);
    }

    /**
     * ÚLTIMO DIA QUE SE PODE AGENDAR, até ao fim desse dia.
     *
     * `endOfDay()` e não a hora exacta: o cliente escolhe um DIA na tira da app,
     * e um limite às 11:03 do 7.º dia era impossível de desenhar num seletor de
     * datas. O dia inteiro está dentro da cativação com folga de uma semana.
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
