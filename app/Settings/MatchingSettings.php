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

    /**
     * Janela de resposta do profissional, em segundos.
     *
     * Os dois modos tem hoje o MESMO numero: a pergunta que se faz muda
     * ("podes agora?" ou "podes quinta as 15h?"), o tempo para responder nao.
     * Ficam separados para poderem voltar a divergir se o trafego o justificar.
     */
    public int $vendor_response_seconds_immediate;

    /** O mesmo, num pedido agendado. Ver acima: hoje é o mesmo valor. */
    public int $vendor_response_seconds_scheduled;

    /**
     * Prazo da FASE DE CONVITES, em segundos, a contar de quando o pedido entra
     * em seleção.
     *
     * Vale enquanto ninguém aceitou. Depois do primeiro sim manda o relógio do
     * cliente (`customer_choice_seconds*`), que não é cortado por este número:
     * as duas fases têm orçamentos próprios, em cadeia.
     *
     * Existe porque o relógio do cliente só arranca no primeiro aceite — até lá
     * o pedido não tinha fim conhecido a não ser o esgotar das ondas.
     *
     * É uma REDE DE SEGURANÇA, e não uma promessa. Está deliberadamente muito
     * acima do que o calendário das ondas precisa: colá-lo a esse calendário
     * fazia o atraso do cron (corre ao minuto) cortar a janela da última onda, e
     * o profissional convidado ao fim tinha menos tempo do que os outros.
     */
    public int $request_deadline_seconds;

    /**
     * Quanto tempo o cliente tem para escolher E pagar, num pedido imediato.
     *
     * É o prazo a sério: nem é cortado pela fase de convites, nem lhe é somado
     * um segundo relógio na escolha. É o número que o contador mostra no ecrã.
     */
    public int $customer_choice_seconds;

    /**
     * O mesmo, num pedido agendado — e hoje é o MESMO valor do imediato.
     *
     * A razão de existir separado já não se aplica. Foi escrito a pensar num
     * cliente que marcava para quinta-feira e fechava a app; mas no agendado ele
     * espera pelo matching, escolhe e só fecha depois de pagar — a mesma
     * situação do imediato. Fica para poder voltar a divergir.
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

    /**
     * Dias úteis até o backoffice ser avisado de um pedido personalizado
     * esquecido em análise. Metade da promessa feita ao cliente, para ainda
     * haver tempo de agir antes de ela ser quebrada.
     */
    public int $custom_review_alert_weekdays;

    /**
     * Dias úteis até um pedido personalizado em análise falhar sozinho. É o
     * prazo que a app promete ao cliente; sem este número, um pedido que
     * ninguém despachasse ficava vivo para sempre e em silêncio.
     */
    public int $custom_review_deadline_weekdays;

    /**
     * TECTO da fase de pagamento, a contar da escolha — não um prazo próprio.
     *
     * O prazo do cliente (`customer_choice_seconds*`) já cobre escolher E pagar.
     * Este número só corta se for MAIS CURTO do que o que resta ao cliente; ao
     * valor de hoje nunca corta primeiro.
     *
     * Não se aplica ao personalizado, onde a promessa feita ao cliente é uma
     * hora para as duas coisas.
     */
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

    /**
     * Raio máximo, em quilómetros, a partir da morada do serviço.
     *
     * Não é uma exclusão dura: quem está dentro é convidado primeiro, e o raio
     * só se abre quando não sobra mais ninguém dentro. Ver a migração
     * 2026_09_22_110000_raio_maximo_do_matching.
     */
    public int $max_radius_km;

    public static function group(): string
    {
        return 'matching';
    }
}
