<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Duas fases, dois prazos, ditos em voz alta.
 *
 * A regra do negocio passa a ser esta, e nao mais do que isto:
 *
 *   1. o profissional tem 120 s para dizer se tem interesse — IGUAL no
 *      imediato e no agendado;
 *   2. o cliente tem 5 minutos, a contar do primeiro sim, para escolher E
 *      pagar.
 *
 * O que estava antes nao dizia isto e nao conseguia diz.
 *
 * O `request_deadline_seconds` (180 s da criacao) era um tecto unico por cima
 * das duas fases. Consequencia aritmetica: uma janela de 180 s para o
 * profissional agendado consumia o tecto inteiro e o cliente ficava com o que
 * sobrasse — que no pior caso era zero. As tres definicoes de escolha foram
 * postas a 180 na migracao `alinhar_prazos_com_o_tecto` exactamente por isso:
 * para deixarem de prometer minutos que o tecto nao dava. Eram tectos com nome
 * de prazo.
 *
 * Um tecto unico nao consegue expressar "120 s para um, depois 300 s para o
 * outro" — as duas fases precisam de orcamentos proprios, em cadeia. E o que
 * esta migracao e a alteracao no `MatchingService` fazem:
 *
 *   · `vendor_response_seconds_*` = 120 nos dois modos. O split fica (para
 *     poder voltar a divergir se o trafego o pedir), mas hoje nao ha diferenca
 *     de negocio entre os dois — a pergunta muda, o tempo para responder nao.
 *
 *   · `customer_choice_seconds*` = 300, e passam a ser o prazo A SERIO: deixam
 *     de ser cortados pelo tecto. Cobrem escolher E pagar, como no
 *     personalizado ja cobriam.
 *
 *   · `request_deadline_seconds` = 600 e deixa de ser um tecto global: passa a
 *     limitar so a FASE DE CONVITES, antes de haver um sim. Depois do primeiro
 *     sim manda o relogio do cliente — o mesmo que ele ve a contar no ecra.
 *
 * Porque 600 e nao 360 (3 ondas x 120 s): o `matching:advance` corre ao minuto,
 * por isso as ondas saem com ate 60 s de atraso cada uma. Um tecto colado ao
 * calendario das ondas cortava a janela da terceira onda e o ultimo
 * profissional convidado tinha menos de 120 s — o numero que esta migracao
 * existe para garantir. E uma rede de seguranca contra um pedido encravado, nao
 * uma promessa: um pedido que ninguem aceita morre muito antes, quando as ondas
 * se esgotam.
 *
 * O `checkout_seconds` continua a existir e continua a valer: e o tecto da fase
 * de pagamento. Aos 300 s de hoje nunca corta primeiro (os 5 minutos do cliente
 * chegam sempre ao mesmo tempo ou antes), mas se alguem o encurtar, corta.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->update('matching.vendor_response_seconds_immediate', fn () => 120);
        $this->migrator->update('matching.vendor_response_seconds_scheduled', fn () => 120);

        $this->migrator->update('matching.customer_choice_seconds', fn () => 300);
        $this->migrator->update('matching.customer_choice_seconds_scheduled', fn () => 300);

        $this->migrator->update('matching.request_deadline_seconds', fn () => 600);
    }

    public function down(): void
    {
        $this->migrator->update('matching.vendor_response_seconds_immediate', fn () => 60);
        $this->migrator->update('matching.vendor_response_seconds_scheduled', fn () => 180);

        $this->migrator->update('matching.customer_choice_seconds', fn () => 180);
        $this->migrator->update('matching.customer_choice_seconds_scheduled', fn () => 180);

        $this->migrator->update('matching.request_deadline_seconds', fn () => 180);
    }
};
