<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Um pedido personalizado em análise deixa de poder ficar lá para sempre.
 *
 * Nasce em PendingReview e só sai de lá quando alguém no backoffice lhe define
 * a duração e as áreas. Até 29/09/2026 NADA o expirava: o `matching:advance`
 * não lhe tocava, o cliente não o conseguia cancelar, e o silêncio não tinha
 * limite dos dois lados — nem ele sabia, nem nós.
 *
 * Dois prazos, em DIAS ÚTEIS, porque quem despacha trabalha em dias úteis:
 *
 *  · ao fim de 1, o backoffice é avisado de que o pedido está a apodrecer.
 *    Metade da promessa feita ao cliente, para ainda haver tempo de agir.
 *  · ao fim de 2, o pedido falha e o cliente é avisado. Dois dias úteis é o
 *    que a app lhe promete no ecrã; prometer e não cumprir sem dizer nada é
 *    pior do que não prometer.
 *
 * Dias ÚTEIS e não corridos: um pedido feito à sexta à noite não pode morrer
 * no domingo, quando ninguém teve oportunidade de lhe pegar.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('matching.custom_review_alert_weekdays', 1);
        $this->migrator->add('matching.custom_review_deadline_weekdays', 2);
    }

    public function down(): void
    {
        $this->migrator->delete('matching.custom_review_alert_weekdays');
        $this->migrator->delete('matching.custom_review_deadline_weekdays');
    }
};
