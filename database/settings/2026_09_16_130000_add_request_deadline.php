<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Prazo global do pedido: 3 minutos a contar de quando o cliente o faz.
 *
 * ANTES nao havia tecto. O relogio do cliente so arrancava no PRIMEIRO
 * ACEITE, e ate la o pedido podia ficar aberto o tempo que a janela dos
 * profissionais permitisse — 30 minutos no agendado. Depois disso arrancavam
 * os 30 minutos de escolha. Uma hora, no pior caso, entre pedir e ter
 * profissional confirmado, com a primeira metade em silencio.
 *
 * AGORA o pedido tem um fim conhecido desde o inicio: 180 segundos depois de
 * ser criado, seja imediato ou agendado. Passado esse tempo sem resolucao, o
 * cliente sabe que ninguem esta disponivel e tenta outra vez, em vez de ficar
 * a olhar para um ecra de espera sem fim.
 *
 * As janelas por modo continuam a existir e a valer — mas agora subordinadas
 * a esta: nenhum convite fica de pe depois de o pedido morrer.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('matching.request_deadline_seconds', 180);
    }

    public function down(): void
    {
        $this->migrator->delete('matching.request_deadline_seconds');
    }
};
