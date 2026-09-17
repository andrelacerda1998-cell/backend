<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Tres numeros que nao queriam dizer nada.
 *
 * O `request_deadline_seconds` (180 s, a contar da criacao) corta por cima de
 * tudo no imediato e no agendado. Consequencia:
 *
 *   - o convite agendado ficava anunciado como 1200 s e valia no maximo 180 s
 *     (90 s na terceira onda, que sai aos 90 s);
 *   - a escolha conta do PRIMEIRO ACEITE, que e sempre depois da criacao, por
 *     isso 200 s (imediato) e 1800 s (agendado) nunca chegavam a morder.
 *
 * Ficavam tres definicoes no backoffice a prometer minutos que o sistema nao
 * dava. Alguem que as afinasse via o numero mudar e o comportamento na mesma —
 * que e a pior maneira de perder a confianca num painel de definicoes.
 *
 * Passam a 180, igual ao tecto. NAO MUDA COMPORTAMENTO NENHUM: o que acontecia
 * ja era isto. O que muda e que agora esta escrito.
 *
 * A premissa que os justificava tambem caiu. Foram escritos a pensar que no
 * agendado o cliente marcava e fechava a app — mas ele espera pelo matching,
 * escolhe, e so sai depois de pagar. Agendado e imediato sao a mesma situacao
 * para quem espera; o que muda e a pergunta feita ao profissional.
 *
 * O personalizado fica de fora: ali o cliente nao esta a espera (a notificacao
 * chega depois de o backoffice despachar) e a hora dele manda sobre o tecto.
 *
 * Para dar mais tempo a qualquer destas fases, o numero a mexer e o TECTO.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->update('matching.vendor_response_seconds_scheduled', fn () => 180);
        $this->migrator->update('matching.customer_choice_seconds', fn () => 180);
        $this->migrator->update('matching.customer_choice_seconds_scheduled', fn () => 180);
    }

    public function down(): void
    {
        $this->migrator->update('matching.vendor_response_seconds_scheduled', fn () => 1200);
        $this->migrator->update('matching.customer_choice_seconds', fn () => 200);
        $this->migrator->update('matching.customer_choice_seconds_scheduled', fn () => 1800);
    }
};
