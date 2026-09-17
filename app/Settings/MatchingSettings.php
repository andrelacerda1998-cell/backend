<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Calibração do fluxo de seleção de profissional — ver docs/matching.md.
 *
 * Nenhum destes números é regra de negócio fixa: são pontos de partida para
 * calibrar com tráfego real. Ficam em definições, e não em constantes, porque
 * o equilíbrio entre "quanto tempo o cliente espera" e "quantas opções tem" só
 * se descobre a ver o que acontece.
 */
class MatchingSettings extends Settings
{
    /** Quantos profissionais o cliente vê. */
    public int $shortlist_size;

    /** Quantos são notificados de cada vez, no agendado. */
    public int $wave_size;

    /** Espera antes de alargar à onda seguinte. */
    public int $wave_interval_seconds;

    /** Até onde vai antes de desistir e dizer "tenta outra vez". */
    public int $max_waves;

    /** Janela de resposta do profissional num pedido imediato (igual à de hoje). */
    public int $vendor_response_seconds_immediate;

    /** Janela de resposta num pedido agendado — há tempo, não há pressa. */
    public int $vendor_response_seconds_scheduled;

    /** Quanto tempo o cliente tem para escolher, num pedido imediato. */
    /**
     * Prazo GLOBAL do pedido, em segundos, a contar de quando o cliente o faz.
     *
     * Vale para imediato e agendado. E um tecto: as janelas por modo
     * (`vendor_response_seconds_*`, `customer_choice_seconds*`) continuam a
     * valer, mas nenhuma pode levar o pedido para alem disto.
     *
     * Existe porque o relogio do cliente so arrancava no primeiro aceite — ate
     * la o pedido nao tinha fim conhecido, e no agendado podia ficar meia hora
     * em silencio antes de a escolha sequer comecar.
     */
    public int $request_deadline_seconds;

    public int $customer_choice_seconds;

    /**
     * O mesmo, num pedido agendado.
     *
     * ATENCAO: hoje este valor nao chega a morder, tal como o do imediato. A
     * escolha conta do primeiro aceite, que e sempre DEPOIS da criacao, por
     * isso qualquer valor >= `request_deadline_seconds` e cortado pelo tecto.
     * Sao tectos por modo, nao prazos — mexer aqui so tem efeito depois de
     * mexer no tecto.
     *
     * A razao de existir separado ja nao se aplica. Foi escrito a pensar num
     * cliente que marcava para quinta-feira e fechava a app; mas no agendado
     * ele espera pelo matching, escolhe e so fecha depois de pagar — a mesma
     * situacao do imediato.
     */
    public int $customer_choice_seconds_scheduled;

    /**
     * O mesmo, num pedido personalizado — e a cobrir escolher E pagar.
     *
     * Nem imediato nem agendado: o cliente descreve o problema, o backoffice
     * define a duracao e so depois os profissionais sao chamados. Quando a
     * notificacao chega ele ja fechou a app, e os minutos do imediato matavam
     * o pedido antes de lhe chegar as maos.
     */
    public int $customer_choice_seconds_custom;

    /** Quanto tempo tem para pagar depois de escolher. */
    public int $checkout_seconds;

    /**
     * Fronteiras das faixas de avaliação, por ordem decrescente.
     *
     * Sem faixas, a ordenação por avaliação decide sempre sozinha: com médias
     * decimais quase não há empates, e o preço e a distância nunca chegam a
     * contar. Agrupar permite que o preço ordene DENTRO da faixa.
     */
    public array $rating_bands;

    /** Abaixo deste número de avaliações, conta como profissional novo. */
    public int $new_vendor_min_ratings;

    /**
     * Atividade recente exigida para entrar na shortlist de um pedido imediato.
     *
     * No imediato ninguém é notificado antes de o cliente escolher, por isso a
     * lista tem de ser uma boa previsão de quem vai mesmo responder.
     */
    public int $require_recent_activity_minutes;

    public static function group(): string
    {
        return 'matching';
    }
}
