<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Agendados com 24 h ou mais de antecedência passam a ser assíncronos.
 *
 * A regra de 29/09 (`cento_e_vinte_segundos_e_cinco_minutos`) deu ao técnico
 * 120 s para responder em qualquer agendado, porque o cliente espera no ecrã.
 * Num serviço marcado para daqui a dois dias, isso matava o pedido antes de
 * algum técnico ver o convite: em 06/10, os 3 agendados do mês morreram em 2 a
 * 3 minutos, com os 10 convites expirados e zero recusas.
 *
 * Esta migração NÃO muda essa regra para os agendados próximos: só acrescenta
 * um regime à parte para os que têm antecedência. Os números são pontos de
 * partida, para afinar com o que acontecer:
 *
 *   · a partir de 24 h de antecedência;
 *   · 2 h para o técnico responder, ondas de 30 em 30 min;
 *   · a fase de convites fecha às 4 h;
 *   · 1 h para o cliente escolher e pagar depois do primeiro sim — o mesmo
 *     do personalizado.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('matching.async_lead_hours', 24);
        $this->migrator->add('matching.vendor_response_seconds_async', 7200);
        $this->migrator->add('matching.wave_interval_seconds_async', 1800);
        $this->migrator->add('matching.request_deadline_seconds_async', 14400);
        $this->migrator->add('matching.customer_choice_seconds_async', 3600);
    }

    public function down(): void
    {
        $this->migrator->delete('matching.async_lead_hours');
        $this->migrator->delete('matching.vendor_response_seconds_async');
        $this->migrator->delete('matching.wave_interval_seconds_async');
        $this->migrator->delete('matching.request_deadline_seconds_async');
        $this->migrator->delete('matching.customer_choice_seconds_async');
    }
};
