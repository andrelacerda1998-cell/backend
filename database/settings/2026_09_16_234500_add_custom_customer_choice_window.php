<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Uma hora para o cliente escolher e pagar, num pedido personalizado.
 *
 * Os outros dois fluxos foram afinados para o cliente que esta a olhar para o
 * ecra: no imediato pediu agora e espera agora (200 segundos), no agendado
 * marcou um dia e ficou a espera de resposta (30 minutos).
 *
 * Um pedido personalizado nao e nem uma coisa nem outra. O cliente descreve o
 * problema por palavras dele, o backoffice define a duracao e as categorias, e
 * so DEPOIS os profissionais sao chamados — pode passar muito tempo entre
 * pedir e haver alguem para escolher. Quando a notificacao chega, ele ja
 * fechou a app e esta a fazer outra coisa. Dar-lhe os mesmos minutos do
 * imediato era matar o pedido antes de ele chegar ao telemovel.
 *
 * A hora cobre escolher E pagar: e o tempo total desde que ha profissionais
 * disponiveis ate o servico estar fechado.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('matching.customer_choice_seconds_custom', 3600);
    }

    public function down(): void
    {
        $this->migrator->delete('matching.customer_choice_seconds_custom');
    }
};
