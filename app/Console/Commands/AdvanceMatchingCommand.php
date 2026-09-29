<?php

namespace App\Console\Commands;

use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Admin\CustomRequestStuckNotification;
use App\Services\Matching\MatchingService;
use App\Settings\MatchingSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

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

        // Personalizados esquecidos em análise. Varrido aqui pela mesma razão
        // que o resto: é o único sítio que corre sozinho, de minuto a minuto,
        // e não depende de ninguém se lembrar.
        $emAnalise = $this->cobrarAnaliseDosPersonalizados($matching, $settings);

        $services = Service::query()
            ->where('status', ServiceStatus::MATCHING)
            ->with(['candidates', 'schedule'])
            ->get();

        if ($services->isEmpty()) {
            if ($abandoned) {
                $this->info("Checkouts abandonados: {$abandoned}");
            }
            if ($emAnalise['avisados'] || $emAnalise['falhados']) {
                $this->info("Em análise — avisados: {$emAnalise['avisados']}, falhados: {$emAnalise['falhados']}");
            }

            return self::SUCCESS;
        }

        foreach ($services as $service) {
            $expired += $matching->expireStale($service);
            $service->load('candidates');

            $customerDeadline = $matching->customerDeadline($service);

            // PRAZO GLOBAL — o pedido tem um fim conhecido desde que e criado.
            //
            // Continua a cortar por cima de tudo, INCLUSIVE da janela de
            // escolha, no imediato e no agendado: ali o cliente esta a olhar
            // para o ecra, e quem disse que sim nao pode ficar preso a uma
            // decisao que nao chega.
            //
            // A excepcao e o PERSONALIZADO depois do primeiro aceite. Ali
            // podem passar horas entre pedir e haver profissionais — o
            // backoffice tem de definir a duracao e as categorias antes de
            // alguem ser chamado — e este tecto conta da criacao. A
            // notificacao chegava ao telemovel com o pedido ja morto. Um prazo
            // que comeca a contar antes de haver alguma coisa para decidir nao
            // e um prazo de decisao: a partir dai manda o relogio do cliente,
            // que e o mesmo que ele ve a contar no ecra.
            $customerClockRules = $service->is_custom && $customerDeadline !== null;

            if (! $customerClockRules) {
                $deadline = $service->matchingStartedAt()?->copy()->addSeconds($settings->request_deadline_seconds);

                if ($deadline && $deadline->isPast()) {
                    $matching->fail($service);
                    $failed++;

                    continue;
                }
            }

            // Alguém já aceitou: a decisão é do cliente. Mas não pode ficar
            // pendente para sempre — se ele fechou a app ou desistiu, os
            // profissionais que responderam ficariam presos a um pedido que
            // nunca resolve, com a janela deles fechada e sem desfecho.
            if ($customerDeadline) {
                if ($customerDeadline->isFuture()) {
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
            if ($lastNotifiedAt && Carbon::parse($lastNotifiedAt)
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
     * Duas contas, porque sao duas promessas diferentes.
     *
     * Num pedido personalizado dissemos ao cliente que tem UMA HORA para
     * escolher e pagar, e o relogio esta a andar no ecra dele desde o primeiro
     * aceite. Um prazo proprio a comecar na escolha davas-lhe mais tempo do que
     * o contador mostra — e um contador que chega a zero sem nada acontecer
     * ensina o cliente a nao acreditar nele.
     *
     * Nos outros, o prazo conta da escolha (`updated_at` do serviço, gravado no
     * momento em que passou a AwaitingPayment). Não há coluna própria para isso
     * e não vale a pena acrescentar uma: o serviço não muda por mais nenhuma
     * razão enquanto está neste estado.
     */
    /**
     * Pedidos personalizados esquecidos em análise.
     *
     * Um personalizado nasce em PendingReview e só sai de lá quando alguém no
     * backoffice lhe define a duração e as áreas. Até isto existir, NADA o
     * expirava: o pedido ficava vivo indefinidamente, o cliente a olhar para
     * "Pedido em análise" sem fim à vista, e ninguém era avisado de nada.
     *
     * Dois prazos, em DIAS ÚTEIS — quem despacha trabalha em dias úteis, e um
     * pedido feito à sexta à noite não pode morrer no domingo:
     *
     *  · ao primeiro, avisa-se o backoffice, UMA vez (o carimbo
     *    `custom_review_alerted_at` é o que impede o aviso de se repetir de
     *    minuto a minuto até deixar de ser lido);
     *  · ao segundo, o pedido falha e o cliente é avisado pelo mesmo caminho
     *    de qualquer outra falha de matching.
     *
     * Dias úteis aqui são dias de semana: não conhecemos os feriados. É uma
     * aproximação a favor do cliente — num feriado o prazo corre mais depressa
     * do que devia, e o pior que acontece é o backoffice ser avisado cedo.
     */
    private function cobrarAnaliseDosPersonalizados(MatchingService $matching, MatchingSettings $settings): array
    {
        $avisados = 0;
        $falhados = 0;

        $emAnalise = Service::query()
            ->where('status', ServiceStatus::PENDING_REVIEW)
            ->where('is_custom', true)
            ->get();

        foreach ($emAnalise as $service) {
            if (! $service->created_at) {
                continue;
            }

            $diasUteis = (int) $service->created_at->diffInWeekdays(now());

            if ($diasUteis >= $settings->custom_review_deadline_weekdays) {
                // A promessa feita ao cliente esgotou-se. Falhar é honesto;
                // deixá-lo vivo era continuar a prometer sem nada por trás.
                $matching->fail($service);
                $falhados++;

                continue;
            }

            if ($diasUteis >= $settings->custom_review_alert_weekdays && ! $service->custom_review_alerted_at) {
                $admins = User::query()
                    ->whereHas('roles', fn ($q) => $q->whereIn('name', ['admin', 'super-admin']))
                    ->get();

                if ($admins->isNotEmpty()) {
                    Notification::send($admins, new CustomRequestStuckNotification(
                        $service,
                        $diasUteis,
                        $settings->custom_review_deadline_weekdays,
                    ));
                }

                // Carimbado mesmo sem destinatários: sem admins o aviso não
                // tem para onde ir, e tentar outra vez daqui a um minuto não
                // muda isso.
                $service->forceFill(['custom_review_alerted_at' => now()])->save();
                $avisados++;
            }
        }

        return ['avisados' => $avisados, 'falhados' => $falhados];
    }

    private function expireAbandonedCheckouts(MatchingService $matching, MatchingSettings $settings): int
    {
        $stuck = Service::query()
            ->where('status', ServiceStatus::AWAITING_PAYMENT)
            ->get();

        $count = 0;

        foreach ($stuck as $service) {
            $deadline = $service->is_custom
                ? $matching->customerDeadline($service)
                : $service->updated_at?->copy()->addSeconds($settings->checkout_seconds);

            if (! $deadline || $deadline->isFuture()) {
                continue;
            }

            if ($matching->expireCheckout($service)) {
                $count++;
            }
        }

        return $count;
    }
}
