<?php

namespace App\Console\Commands;

use App\Enums\Services\CandidateStatus;
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
        $services = Service::query()
            ->where('status', ServiceStatus::MATCHING)
            ->with(['candidates', 'schedule'])
            ->get();

        if ($services->isEmpty()) {
            return self::SUCCESS;
        }

        $expired = 0;
        $advanced = 0;
        $failed = 0;

        foreach ($services as $service) {
            $expired += $matching->expireStale($service);
            $service->load('candidates');

            // Alguém já aceitou: a decisão é do cliente, não há nada a fazer.
            if ($service->candidates()->accepted()->exists()) {
                continue;
            }

            // Imediato: só uma pessoa é chamada de cada vez. Se a janela dela
            // fechou, passa-se à seguinte da lista.
            if (! $matching->isScheduled($service)) {
                $stillWaiting = $service->candidates()
                    ->where('status', CandidateStatus::NOTIFIED)
                    ->exists();

                if ($stillWaiting) {
                    continue;
                }

                // Sem ninguém chamado e sem ninguém por chamar, o pedido morre
                // aqui — mas só depois de a shortlist se esgotar.
                $matching->advanceImmediate($service) ? $advanced++ : $failed++;

                continue;
            }

            // Agendado: alarga a onda quando a anterior já teve tempo de
            // responder. Alargar antes disso seria chamar gente a mais para o
            // mesmo trabalho — exatamente o que as ondas evitam.
            $lastNotifiedAt = $service->candidates()->max('notified_at');

            // Comparação explícita e não `diffInSeconds`: no Carbon 3 a
            // diferença vem COM SINAL, por isso uma data no passado dava um
            // número negativo, sempre menor do que o intervalo — e a onda
            // seguinte nunca saía.
            if ($lastNotifiedAt && \Carbon\Carbon::parse($lastNotifiedAt)
                ->addSeconds($settings->wave_interval_seconds)
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

        if ($expired || $advanced || $failed) {
            $this->info("Convites expirados: {$expired} · pedidos alargados: {$advanced} · pedidos desistidos: {$failed}");
        }

        return self::SUCCESS;
    }
}
