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

    /** Quanto tempo o cliente tem para escolher antes de as propostas caducarem. */
    public int $customer_choice_seconds;

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
