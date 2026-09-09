<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Janela de escolha propria para os pedidos agendados: 1800 s (30 min).
 *
 * Ate agora o agendado nem passava pelo matching, por isso um so valor
 * chegava. Passa a passar — e as duas situacoes nao se parecem nada. No
 * imediato o cliente esta a olhar para o ecra e 200 s sao suficientes; no
 * agendado marcou para quinta-feira e fechou a app.
 *
 * O relogio conta a partir da PRIMEIRA aceitacao. Com 200 s no agendado, o
 * pedido morria minutos depois de ter sido feito, com profissionais
 * disponiveis do outro lado e a janela de resposta deles
 * (`vendor_response_seconds_scheduled`) ainda aberta durante meia hora. 1800 s
 * alinha as duas: a escolha do cliente dura o mesmo que os convites.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('matching.customer_choice_seconds_scheduled', 1800);
    }

    public function down(): void
    {
        $this->migrator->delete('matching.customer_choice_seconds_scheduled');
    }
};
