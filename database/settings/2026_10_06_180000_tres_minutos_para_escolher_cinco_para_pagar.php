<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Dois relógios para o cliente (decisão do André, 06/10/2026):
 *
 *  · Pedir agora: 3 minutos para escolher, a contar do ÚLTIMO profissional
 *    que aceitou (`customer_choice_seconds`); cada novo "sim" recomeça a
 *    contagem, mas nunca para lá de 6 minutos depois do PRIMEIRO
 *    (`customer_choice_cap_seconds`).
 *  · Agendado: 10 minutos para escolher, a contar do último "sim"
 *    (`customer_choice_seconds_scheduled`) — sem urgência, o serviço é
 *    noutro dia.
 *  · 5 minutos para pagar, a contar da escolha (`checkout_seconds`, que já
 *    estava a 300 e deixa de ser só um tecto: passa a ser o prazo).
 *
 * Até aqui era um só relógio de 5 minutos para as duas coisas, a contar do
 * primeiro aceite — quem escolhia no fim ficava sem tempo para pagar. Ver
 * MatchingService::customerDeadline.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->update('matching.customer_choice_seconds', fn () => 180);
        $this->migrator->add('matching.customer_choice_cap_seconds', 360);
        $this->migrator->update('matching.customer_choice_seconds_scheduled', fn () => 600);
        $this->migrator->update('matching.checkout_seconds', fn () => 300);
    }

    public function down(): void
    {
        $this->migrator->update('matching.customer_choice_seconds', fn () => 300);
        $this->migrator->delete('matching.customer_choice_cap_seconds');
        $this->migrator->update('matching.customer_choice_seconds_scheduled', fn () => 300);
        $this->migrator->update('matching.checkout_seconds', fn () => 300);
    }
};
