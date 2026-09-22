<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * O matching passa a ter um raio.
 *
 * Até aqui não tinha um único predicado geográfico: filtrava por tipo de
 * serviço, conta de teste, estado online, disponibilidade e agenda. A distância
 * entrava apenas como terceiro critério de desempate.
 *
 * O resultado medido na auditoria: para o mesmo serviço em Lisboa, dois
 * profissionais elegíveis — um a 1 km por 34,11 €, outro a 398 km por 554,98 €.
 * E o cliente lia, enquanto esperava, "Avisámos os técnicos da tua zona".
 *
 * 50 km, e não é uma exclusão dura: quem está dentro é convidado primeiro, e só
 * quando não sobra mais ninguém dentro é que o raio se abre. Em zonas com pouca
 * cobertura o pedido continua a ter hipótese — o que deixa de acontecer é
 * alguém a 400 km aparecer ao lado de alguém da rua.
 *
 * Fica em definições e não no código porque é um número de negócio: afina-se
 * conforme a cobertura for crescendo.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('matching.max_radius_km', 50);
    }

    public function down(): void
    {
        $this->migrator->delete('matching.max_radius_km');
    }
};
