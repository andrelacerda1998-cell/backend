<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * A janela de resposta do profissional no agendado desce de 30 para 20 min.
 *
 * O numero foi posto em 1800 s a pensar em quem so olha para o telemovel de
 * hora a hora. Mas nao e so o profissional que espera: o pedido fica parado
 * ate alguem responder, e so entao arranca a janela de escolha do cliente —
 * que no agendado sao outros 30 minutos. No pior caso, uma hora entre pedir e
 * ter profissional confirmado.
 *
 * 20 minutos continuam a ser vinte vezes o que o imediato da (60 s) e chegam
 * bem para quem esta a trabalhar olhar para o telemovel entre duas tarefas.
 *
 * O QUE ISTO NAO MUDA: as ondas. O intervalo entre elas e
 * `wave_interval_seconds` (45 s) nos dois modos, por isso as tres ondas
 * continuam a sair nos primeiros 90 segundos. O que encurta e so o tempo que
 * cada convite fica de pe.
 *
 * Reverter e repor 1800.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->update('matching.vendor_response_seconds_scheduled', fn () => 1200);
    }

    public function down(): void
    {
        $this->migrator->update('matching.vendor_response_seconds_scheduled', fn () => 1800);
    }
};
