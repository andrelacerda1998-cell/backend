<?php

namespace App\Services\Matching;

use App\Enums\Services\CandidateStatus;
use App\Events\Matching\MatchingCandidateAcceptedEvent;
use App\Events\Matching\MatchingCandidateLostEvent;
use App\Events\Matching\MatchingInvitationEvent;
use App\Events\Matching\MatchingRequestClosedEvent;
use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Notifications\Vendor\MatchingInvitationNotification;
use App\Settings\MatchingSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Põe candidatos em cima da mesa para um serviço — ver docs/matching.md.
 *
 * UM caminho só, para os dois modos: notifica por ondas, dos melhores para
 * baixo, e fecha ao terceiro sim. O cliente escolhe entre quem se disponibilizou.
 *
 * O imediato foi unificado com o agendado por decisão de produto (ver nota
 * abaixo). Antes tinha um percurso próprio — shortlist sem notificar, cliente
 * escolhia, e só o escolhido era chamado — para que ninguém aceitasse em vão.
 * A troca é deliberada: o cliente passa a esperar alguns segundos e alguns
 * profissionais aceitam sem ganhar, mas em contrapartida ele escolhe entre
 * pessoas que CONFIRMARAM disponibilidade, e não entre uma previsão de quem
 * estaria livre. Deixa também de haver dois percursos a manter.
 *
 * O custo continua a ser real, e mitiga-se com o tamanho da onda e com a
 * duração da janela: convidar três com janela curta é diferente de convidar
 * vinte com janela longa. Ambos são definições, não constantes.
 *
 * Não cobra nem devolve dinheiro. O pagamento acontece depois de haver aceitação
 * — que é a fraqueza que este fluxo veio corrigir.
 */
class MatchingService
{
    public function __construct(
        private VendorRankingService $ranking,
        private MatchingSettings $settings,
    ) {
    }

    /**
     * Onda seguinte de convites, nos dois modos.
     *
     * Devolve só os candidatos criados agora — são esses que há que notificar.
     * Quem já foi notificado não é chamado outra vez.
     *
     * @return Collection<int, ServiceCandidate>
     */
    public function dispatchNextWave(Service $service): Collection
    {
        if ($this->hasEnoughAcceptances($service)) {
            return collect();
        }

        $wave = ((int) $service->candidates()->max('wave')) + 1;

        if ($wave > $this->settings->max_waves) {
            return collect();
        }

        $alreadySeen = $service->candidates()->pluck('vendor_id')->all();
        $immediate = ! $this->isScheduled($service);

        $ranked = $this->rankFor($service, immediate: $immediate)
            ->reject(fn (RankedVendor $c) => in_array($c->vendor->id, $alreadySeen, true))
            ->values();

        if ($ranked->isEmpty()) {
            return collect();
        }

        // A vaga do recém-chegado aplica-se à onda, para não ficar sempre para
        // uma onda que talvez nunca chegue a existir.
        $batch = $this->ranking->shortlist($ranked, $this->settings->wave_size);

        $candidates = $this->persist($service, $batch, CandidateStatus::NOTIFIED, $wave);

        foreach ($candidates as $candidate) {
            $this->notifyVendor($candidate->loadMissing('vendor'), MatchingInvitationEvent::class);
            // Quem tem auto-aceitação ligada responde já, como no convite
            // individual. Sem isto, a unificação teria desligado a funcionalidade
            // em silêncio para os pedidos imediatos.
            $this->autoAcceptIfEnabled($candidate);
        }

        return $candidates;
    }

    /**
     * Regista o sim de um profissional.
     *
     * Aceitar NÃO reserva a agenda: é "estou disponível e interessado", não um
     * compromisso. Só o escolhido bloqueia o slot — é o que permite responder a
     * dois pedidos em paralelo sem sair prejudicado.
     */
    public function accept(ServiceCandidate $candidate): bool
    {
        return DB::transaction(function () use ($candidate) {
            // Lock no SERVIÇO antes do candidato: a contagem de aceitações é
            // sobre o serviço todo, e sem serializar aqui dois profissionais a
            // aceitarem ao mesmo tempo passavam ambos pela verificação do
            // "terceiro sim" e o pedido fechava com quatro aceites.
            // A ordem (serviço, depois candidato) é a mesma do select() e do
            // advance — ordens invertidas dariam deadlock.
            $service = Service::whereKey($candidate->service_id)->lockForUpdate()->first();

            if (! $service || $service->status !== ServiceStatus::MATCHING) {
                return false;
            }

            $candidate = ServiceCandidate::whereKey($candidate->getKey())->lockForUpdate()->first();

            if (! $candidate || ! $candidate->status->isLive() || $candidate->hasExpired()) {
                return false;
            }

            // Ao terceiro sim o pedido fecha. Sem este travão, quem responde
            // depois aceita para uma vaga que já não existe.
            if ($this->hasEnoughAcceptances($candidate->service)) {
                $candidate->update([
                    'status' => CandidateStatus::LOST,
                    'responded_at' => now(),
                ]);

                $this->notifyVendor($candidate, MatchingRequestClosedEvent::class);

                return false;
            }

            $candidate->update([
                'status' => CandidateStatus::ACCEPTED,
                'responded_at' => now(),
            ]);

            // O cliente vê o profissional aparecer no momento em que aceita, em
            // vez de esperar que a janela feche. É o que torna a espera
            // progressiva: aos poucos segundos já há uma opção para escolher.
            $this->notifyCustomer($candidate, MatchingCandidateAcceptedEvent::class);

            // Ao terceiro sim o pedido fecha para todos os outros, já.
            if ($this->hasEnoughAcceptances($candidate->service)) {
                $this->closeRemaining($candidate->service, $candidate->id);
            }

            return true;
        });
    }

    /**
     * Recusar retira este profissional da corrida e mais nada.
     *
     * Os outros convidados continuam a poder aceitar, e o cliente continua a
     * escolher entre quem aceitou. Antes da unificação, uma recusa no imediato
     * obrigava a chamar logo o seguinte, porque só uma pessoa tinha sido
     * chamada; com vários convidados em paralelo isso deixa de ser preciso.
     */
    public function decline(ServiceCandidate $candidate): void
    {
        if (! $candidate->status->isLive()) {
            return;
        }

        $candidate->update([
            'status' => CandidateStatus::DECLINED,
            'responded_at' => now(),
        ]);
    }

    /**
     * Fecha convites cuja janela passou.
     *
     * O estado já era avaliado por leitura — um convite expirado nunca podia
     * ser aceite — mas as linhas ficavam em `notified` para sempre. Sem isto
     * não há como distinguir "não respondeu" de "ainda a pensar", nem nas
     * consultas nem nas métricas.
     *
     * @return int quantos foram fechados
     */
    public function expireStale(Service $service): int
    {
        $stale = $service->candidates()->stale()->get();

        if ($stale->isEmpty()) {
            return 0;
        }

        // Sem `responded_at`: quem não respondeu não respondeu. Carimbar uma hora
        // de resposta a quem deixou a janela passar estragava qualquer métrica de
        // tempo de resposta — a média passava a incluir silêncios.
        $service->candidates()->stale()->update([
            'status' => CandidateStatus::EXPIRED,
        ]);

        return $stale->count();
    }

    /**
     * O cliente escolheu. Marca o escolhido e encerra os restantes.
     *
     * Os perdedores ficam gravados como LOST em vez de apagados: sem isso não há
     * como responder a "porque é que não fiquei com este serviço?" nem medir se
     * o ranking está a fazer o que se espera.
     */
    public function select(ServiceCandidate $candidate): bool
    {
        return DB::transaction(function () use ($candidate) {
            $service = Service::whereKey($candidate->service_id)->lockForUpdate()->first();

            if (! $service || $service->status !== ServiceStatus::MATCHING) {
                return false;
            }

            $fresh = ServiceCandidate::whereKey($candidate->getKey())->first();

            if (! $fresh || $fresh->status !== CandidateStatus::ACCEPTED) {
                return false;
            }

            // A agenda é reverificada AQUI, e não só no convite: entre convidar e
            // o cliente decidir, o profissional pode ter ficado com o bloco
            // ocupado por outro pedido. Com a auto-aceitação ligada isto deixa de
            // ser hipótese remota — ele diz que sim a tudo, e dois clientes podem
            // pedir a mesma hora.
            //
            // Falhar aqui é o comportamento certo: o cliente escolhe outro dos
            // disponíveis em vez de ficar com uma marcação dupla.
            $startAt = $this->scheduledStartAt($service);

            if ($startAt && $fresh->vendor) {
                $minutes = (int) ($service->serviceType?->time ?: 60);

                if (! $fresh->vendor->hasFreeSlot($startAt, $startAt->copy()->addMinutes($minutes))) {
                    $fresh->update(['status' => CandidateStatus::LOST]);
                    $this->notifyVendor($fresh, MatchingCandidateLostEvent::class);

                    return false;
                }
            }

            $fresh->update(['status' => CandidateStatus::SELECTED]);

            $losers = $service->candidates()
                ->whereKeyNot($fresh->getKey())
                ->live()
                ->with('vendor')
                ->get();

            $service->candidates()
                ->whereKeyNot($fresh->getKey())
                ->live()
                ->update(['status' => CandidateStatus::LOST]);

            // Em segundos e com motivo. Silêncio depois de um "sim" é o que
            // ensina o profissional a deixar de responder.
            foreach ($losers as $loser) {
                $this->notifyVendor($loser, MatchingCandidateLostEvent::class);
            }

            $service->fill([
                'vendor_id' => $fresh->vendor_id,
                'distance' => (string) $fresh->quoted_distance,
                'status' => ServiceStatus::AWAITING_PAYMENT,
            ]);

            // `amount` e `amount_for_vendor` estão fora do $fillable de propósito
            // — são dinheiro, protegidos de atribuição em massa. Atribuem-se aqui
            // um a um, com o valor congelado do candidato, para o preço que o
            // cliente viu ser exatamente o que lhe é cobrado.
            $service->amount = $fresh->quoted_amount;
            $service->amount_for_vendor = $fresh->quoted_amount_for_vendor;
            $service->save();

            return true;
        });
    }

    /** Ninguém aceitou, ou esgotaram-se as ondas: o cliente tenta outra vez. */
    public function fail(Service $service): void
    {
        DB::transaction(function () use ($service) {
            $pending = $service->candidates()->live()->with('vendor')->get();

            $service->candidates()->live()->update(['status' => CandidateStatus::LOST]);
            $service->update(['status' => ServiceStatus::MATCHING_FAILED]);

            foreach ($pending as $candidate) {
                $this->notifyVendor($candidate, MatchingRequestClosedEvent::class);
            }
        });
    }

    /**
     * O cliente escolheu e não pagou dentro do prazo.
     *
     * Sem isto o serviço ficava em AwaitingPayment para sempre: o escolhido
     * marcado como SELECTED sem nunca receber trabalho nem desfecho, e os
     * outros já dispensados. É o pior caso possível para quem respondeu — não
     * ganhou, não perdeu, e ninguém lhe disse nada.
     *
     * O pedido termina como MatchingFailed e não Canceled: para o cliente o
     * resultado é o mesmo que ninguém ter aparecido, e é o estado que a app já
     * sabe apresentar como "tenta outra vez".
     */
    public function expireCheckout(Service $service): bool
    {
        return DB::transaction(function () use ($service) {
            $locked = Service::whereKey($service->getKey())->lockForUpdate()->first();

            // Pode ter pago entre a leitura e este ponto.
            if (! $locked || $locked->status !== ServiceStatus::AWAITING_PAYMENT) {
                return false;
            }

            $selected = $locked->candidates()
                ->where('status', CandidateStatus::SELECTED)
                ->with('vendor')
                ->get();

            $locked->candidates()
                ->where('status', CandidateStatus::SELECTED)
                ->update(['status' => CandidateStatus::LOST]);

            $locked->vendor_id = null;
            $locked->status = ServiceStatus::MATCHING_FAILED;
            $locked->save();

            foreach ($selected as $candidate) {
                $this->notifyVendor($candidate, MatchingRequestClosedEvent::class);
            }

            return true;
        });
    }

    /** Fecha o pedido para quem ainda não respondeu e avisa-o. */
    private function closeRemaining(Service $service, int $exceptId): void
    {
        $remaining = $service->candidates()
            ->whereKeyNot($exceptId)
            ->where('status', CandidateStatus::NOTIFIED)
            ->with('vendor')
            ->get();

        foreach ($remaining as $candidate) {
            $this->notifyVendor($candidate, MatchingRequestClosedEvent::class);
        }
    }

    /** Chama um profissional e põe a janela dele a correr. */
    public function invite(ServiceCandidate $candidate, int $windowSeconds): void
    {
        $candidate->update([
            'status' => CandidateStatus::NOTIFIED,
            'notified_at' => now(),
            'expires_at' => now()->addSeconds($windowSeconds),
        ]);

        $this->notifyVendor($candidate->refresh(), MatchingInvitationEvent::class);
        $this->autoAcceptIfEnabled($candidate);
    }

    /**
     * Responde por ele quando tem a auto-aceitação ligada.
     *
     * Aceitar um convite não reserva a agenda nem garante o trabalho, por isso
     * responder automaticamente é barato para o profissional: entra em mais
     * seleções sem custo. E ele já só é convidado para blocos que tem livres
     * (Vendor::hasFreeSlot).
     *
     * O que NÃO fica garantido é que continue livre até o cliente decidir —
     * dois clientes podem pedir a mesma hora e ambos convidá-lo. É por isso que
     * o `select()` volta a verificar a agenda antes de o atribuir.
     */
    private function autoAcceptIfEnabled(ServiceCandidate $candidate): void
    {
        $vendor = $candidate->vendor;
        $service = $candidate->service;

        if (! $vendor || ! $service) {
            return;
        }

        if (! $vendor->autoAcceptsOn($this->scheduledStartAt($service))) {
            return;
        }

        $this->accept($candidate);
    }

    private function notifyVendor(ServiceCandidate $candidate, string $event): void
    {
        $userId = $candidate->vendor?->user_id;

        if (! $userId) {
            return;
        }

        $event::dispatch($userId, [
            'service_id' => $candidate->service_id,
            'candidate_id' => $candidate->id,
            'amount_for_vendor' => $candidate->quoted_amount_for_vendor,
            'expires_at' => $candidate->expires_at?->toIso8601String(),
        ]);

        // Só o convite leva push. Os outros eventos (perdeu, pedido fechou) são
        // informação de desfecho e chegam quando ele abrir a app — tocar-lhe o
        // telemóvel para dizer que não ganhou seria castigá-lo por ter aceitado.
        //
        // O websocket acima chega a quem tem a app aberta; este push é para
        // quem a tem fechada, que é a maioria enquanto trabalha.
        if ($event !== MatchingInvitationEvent::class) {
            return;
        }

        try {
            $candidate->vendor?->user?->notify(new MatchingInvitationNotification($candidate));
        } catch (\Throwable $e) {
            // Um push falhado não pode impedir os restantes convites da onda.
            report($e);
        }
    }

    private function notifyCustomer(ServiceCandidate $candidate, string $event): void
    {
        $customerId = $candidate->service?->customer_id;

        if (! $customerId) {
            return;
        }

        $event::dispatch($customerId, [
            'service_id' => $candidate->service_id,
            'candidate_id' => $candidate->id,
            'vendor_id' => $candidate->vendor_id,
            'amount' => $candidate->quoted_amount,
            'rank' => $candidate->rank,
        ]);
    }

    /**
     * Um pedido em seleção agendado ainda NÃO tem linha de agenda.
     *
     * `schedule.vendor_id` é NOT NULL, e durante a seleção ainda não há
     * profissional — a linha só pode nascer depois de o cliente escolher e
     * pagar. Até lá a intenção vive em `pending_schedule_data`, que é o mesmo
     * mecanismo que o fluxo antigo já usava enquanto esperava pelo 3DS/MBWay.
     */
    public function isScheduled(Service $service): bool
    {
        if ($service->schedule) {
            return true;
        }

        return (bool) ($service->pending_schedule_data['scheduled'] ?? false);
    }

    /**
     * Instante de início pretendido, enquanto a agenda ainda não pode existir.
     *
     * Prefere sempre a HORA e não só o dia: a verificação de disponibilidade
     * compara o bloco com o horário de trabalho, e um serviço marcado para as
     * 15h avaliado à meia-noite cai sempre fora — o que rejeitava toda a gente
     * em silêncio.
     */
    public function scheduledStartAt(Service $service): ?\Carbon\CarbonInterface
    {
        $schedule = $service->schedule;

        if ($schedule) {
            return \Carbon\Carbon::parse($schedule->scheduled_day.' '.$schedule->scheduled_time_start);
        }

        $pending = $service->pending_schedule_data['schedule'] ?? null;

        if (! $pending) {
            return null;
        }

        $start = $pending['scheduled_time_start'] ?? null;

        return $start
            ? \Carbon\Carbon::parse($start)
            : (($pending['scheduled_day'] ?? null) ? \Carbon\Carbon::parse($pending['scheduled_day']) : null);
    }

    public function hasEnoughAcceptances(Service $service): bool
    {
        return $service->candidates()->accepted()->count() >= $this->settings->shortlist_size;
    }

    /**
     * Candidatos que o cliente pode escolher agora: os que disseram que sim.
     *
     * Só aceitações, nos dois modos. Mostrar quem ainda não respondeu seria
     * oferecer uma previsão como se fosse uma confirmação — e o cliente
     * escolheria alguém que ainda pode recusar.
     *
     * Se só houver dois, mostram-se dois: é regra de negócio, não se espera
     * por um número redondo.
     *
     * @return Collection<int, ServiceCandidate>
     */
    public function selectableFor(Service $service): Collection
    {
        return $service->candidates()
            ->where('status', CandidateStatus::ACCEPTED)
            ->orderBy('rank')
            ->get();
    }

    /**
     * @return Collection<int, RankedVendor>
     */
    private function rankFor(Service $service, bool $immediate): Collection
    {
        $service->loadMissing('serviceType', 'customer');

        return $this->ranking->rank(
            serviceType: $service->serviceType,
            address: new \App\DTO\Services\AddressCoordinatesDTO(
                (float) ($service->address['latitude'] ?? 0),
                (float) ($service->address['longitude'] ?? 0),
            ),
            customer: $service->customer,
            immediate: $immediate,
            scheduledFor: $this->scheduledStartAt($service),
            quantity: $service->quantity ?? 1,
        );
    }

    /**
     * @param  Collection<int, RankedVendor>  $ranked
     * @return Collection<int, ServiceCandidate>
     */
    private function persist(Service $service, Collection $ranked, CandidateStatus $status, int $wave): Collection
    {
        // Janela por modo: num pedido para agora o profissional tem de responder
        // depressa, senão o cliente está a olhar para um ecrã de espera; num
        // agendado há tempo e a pressa só serve para o fazer recusar.
        $window = now()->addSeconds(
            $this->isScheduled($service)
                ? $this->settings->vendor_response_seconds_scheduled
                : $this->settings->vendor_response_seconds_immediate
        );

        // O rank é contínuo ao longo das ondas. O ranking numera 1..N a cada
        // chamada, por isso sem este deslocamento a segunda onda voltava a ter
        // um rank 1 — e o cliente via dois "recomendados", ordenados ao acaso.
        $rankOffset = (int) $service->candidates()->max('rank');

        return $ranked->map(function (RankedVendor $c) use ($service, $status, $wave, $window, $rankOffset) {
            return ServiceCandidate::updateOrCreate(
                ['service_id' => $service->id, 'vendor_id' => $c->vendor->id],
                [
                    'rank' => $rankOffset + $c->rank,
                    'wave' => $wave,
                    'status' => $status,
                    'rating_band' => $c->ratingBand,
                    'rating_average' => $c->ratingAverage === null ? null : (int) round($c->ratingAverage * 100),
                    'rating_count' => $c->ratingCount,
                    'quoted_amount' => $c->quotedAmount,
                    'quoted_amount_for_vendor' => $c->quotedAmountForVendor,
                    'quoted_distance' => $c->distance,
                    'is_new_vendor_slot' => $c->isNewVendorSlot,
                    'notified_at' => $status === CandidateStatus::NOTIFIED ? now() : null,
                    'expires_at' => $status === CandidateStatus::NOTIFIED ? $window : null,
                ]
            );
        });
    }
}
