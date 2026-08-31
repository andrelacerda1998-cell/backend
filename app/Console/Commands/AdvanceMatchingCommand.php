<?php

namespace App\Console\Commands;

use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Services\Matching\MatchingService;
use App\Settings\MatchingSettings;
use Illuminate\Console\Command;

/**
 * Faz o tempo passar nos pedidos em seleção — ver docs/matching.md.
 *
 * Sem isto, um pedido que não encha à primeira onda fica parado para sempre e
 * o cliente espera por alguém que nunca vai ser chamado.
 *
 * É um comando agendado e não um job com atraso, de propósito: um job perdido
 * (fila reiniciada, worker morto) deixaria o pedido encalhado sem ninguém dar
 * por isso. Um comando que corre de minuto a minuto recupera sozinho.
 */
class AdvanceMatchingCommand extends Command
{
    protected $signature = 'matching:advance';

    protected $description = 'Fecha convites expirados, alarga as ondas e desiste dos pedidos sem resposta';

    public function handle(MatchingService $matching, MatchingSettings $settings): int
    {
        $expired = 0;
        $advanced = 0;
        $failed = 0;
        $abandoned = 0;

        // Escolhidos que nunca chegaram a ser pagos. Varrido aqui e não num job
        // com atraso pela mesma razão que o resto: um job perdido deixava o
        // serviço encalhado e o profissional escolhido sem desfecho nenhum.
        $abandoned = $this->expireAbandonedCheckouts($matching, $settings);

        $services = Service::query()
            ->where('status', ServiceStatus::MATCHING)
            ->with(['candidates', 'schedule'])
            ->get();

        if ($services->isEmpty()) {
            if ($abandoned) {
                $this->info("Checkouts abandonados: {$abandoned}");
            }

            return self::SUCCESS;
        }

        foreach ($services as $service) {
            $expired += $matching->expireStale($service);
            $service->load('candidates');

            // Alguém já aceitou: a decisão é do cliente. Mas não pode ficar
            // pendente para sempre — se ele fechou a app ou desistiu, os
            // profissionais que responderam ficariam presos a um pedido que
            // nunca resolve, com a janela deles fechada e sem desfecho.
            $firstAcceptedAt = $service->candidates()->accepted()->min('responded_at');

            if ($firstAcceptedAt) {
                if (\Carbon\Carbon::parse($firstAcceptedAt)
                    ->addSeconds($settings->customer_choice_seconds)
                    ->isFuture()) {
                    continue;
                }

                $matching->fail($service);
                $failed++;

                continue;
            }

            // Alarga a onda quando a anterior já teve tempo de responder, nos
            // dois modos. Alargar antes disso seria chamar gente a mais para o
            // mesmo trabalho — exatamente o que as ondas evitam.
            //
            // No imediato o intervalo é a própria janela de resposta: não faz
            // sentido esperar mais do que o tempo que se deu a quem já foi
            // convidado, com o cliente parado num ecrã de espera.
            $interval = $matching->isScheduled($service)
                ? $settings->wave_interval_seconds
                : $settings->vendor_response_seconds_immediate;

            $lastNotifiedAt = $service->candidates()->max('notified_at');

            // Comparação explícita e não `diffInSeconds`: no Carbon 3 a
            // diferença vem COM SINAL, por isso uma data no passado dava um
            // número negativo, sempre menor do que o intervalo — e a onda
            // seguinte nunca saía.
            if ($lastNotifiedAt && \Carbon\Carbon::parse($lastNotifiedAt)
                ->addSeconds($interval)
                ->isFuture()) {
                continue;
            }

            if ($matching->dispatchNextWave($service)->isNotEmpty()) {
                $advanced++;

                continue;
            }

            // Ondas esgotadas e ninguém aceitou. A regra do negócio é dizer ao
            // cliente para tentar outra vez, e não deixá-lo à espera.
            if (! $service->candidates()->live()->exists()) {
                $matching->fail($service);
                $failed++;
            }
        }

        if ($expired || $advanced || $failed || $abandoned) {
            $this->info("Convites expirados: {$expired} · pedidos alargados: {$advanced} · pedidos desistidos: {$failed} · checkouts abandonados: {$abandoned}");
        }

        return self::SUCCESS;
    }

    /**
     * Serviços escolhidos que nunca chegaram a ser pagos.
     *
     * O prazo conta a partir da escolha (`updated_at` do serviço, gravado no
     * momento em que passou a AwaitingPayment). Não há coluna própria para isso
     * e não vale a pena acrescentar uma: o serviço não muda por mais nenhuma
     * razão enquanto está neste estado.
     */
    private function expireAbandonedCheckouts(MatchingService $matching, MatchingSettings $settings): int
    {
        $cutoff = now()->subSeconds($settings->checkout_seconds);

        $stuck = Service::query()
            ->where('status', ServiceStatus::AWAITING_PAYMENT)
            ->where('updated_at', '<=', $cutoff)
            ->get();

        $count = 0;

        foreach ($stuck as $service) {
            if ($matching->expireCheckout($service)) {
                $count++;
            }
        }

        return $count;
    }
}
