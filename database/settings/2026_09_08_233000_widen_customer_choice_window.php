<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * O cliente passa a ter 200 segundos para escolher, em vez de 120.
 *
 * Este relogio conta a partir da PRIMEIRA aceitacao, e nao do fim das ondas.
 * Com 120 s o pedido podia morrer com convites ainda validos por responder: o
 * primeiro profissional aceita aos 10 s, o cliente pondera, e aos 130 s o
 * pedido falha — apesar de a terceira onda so sair aos 120 s e ainda ter
 * gente por responder.
 *
 * 200 s cobre a janela toda do imediato com folga: as ondas esgotam-se aos
 * ~180 s (3 ondas x 60 s de janela de resposta, que no imediato e tambem o
 * intervalo entre ondas — ver AdvanceMatchingCommand), por isso a escolha do
 * cliente ja nunca caduca enquanto ainda podem aparecer opcoes novas.
 *
 * A janela de resposta do profissional no imediato fica nos 60 s de origem:
 * e o que mantem o pior caso — ninguem aceita — nos ~3 minutos.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->update('matching.customer_choice_seconds', fn () => 200);
    }

    public function down(): void
    {
        $this->migrator->update('matching.customer_choice_seconds', fn () => 120);
    }
};
