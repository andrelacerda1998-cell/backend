<?php

namespace App\Services\Matching;

use App\Enums\Services\CandidateStatus;
use App\Events\Matching\MatchingCandidateAcceptedEvent;
use App\Events\Matching\MatchingCandidateLostEvent;
use App\Events\Matching\MatchingFallbackEvent;
use App\Events\Matching\MatchingInvitationEvent;
use App\Events\Matching\MatchingRequestClosedEvent;
use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Models\ServiceCandidate;
use App\Settings\MatchingSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Põe candidatos em cima da mesa para um serviço — ver docs/matching.md.
 *
 * Dois caminhos, pelo princípio de que ninguém deve aceitar em vão:
 *
 *  - IMEDIATO: monta a shortlist sem notificar ninguém. O cliente escolhe, e só
 *    o escolhido é chamado. O custo de aceitar e não ganhar passa a ser zero, e
 *    não há janela de espera antes de o cliente ver opções.
 *
 *  - AGENDADO: notifica por ondas, dos melhores para baixo, e fecha ao terceiro
 *    sim. Em vez de a cidade toda aceitar para três ganharem, aceitam três ou
 *    quatro.
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
     * Shortlist para pedido imediato: grava os candidatos SEM os notificar.
     *
     * @return Collection<int, ServiceCandidate>
     */
    public function buildShortlist(Service $service): Collection
    {
        $ranked = $this->rankFor($service, immediate: true);

        if ($ranked->isEmpty()) {
            return collect();
        }

        return $this->persist($service, $this->ranking->shortlist($ranked), CandidateStatus::SHORTLISTED, wave: 1);
    }

    /**
     * Onda seguinte de um pedido agendado.
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

        $ranked = $this->rankFor($service, immediate: false)
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

    public function decline(ServiceCandidate $candidate): void
    {
        if (! $candidate->status->isLive()) {
            return;
        }

        $candidate->update([
            'status' => CandidateStatus::DECLINED,
            'responded_at' => now(),
        ]);

        // Num pedido imediato só uma pessoa foi chamada: se ela recusa, o
        // cliente ficaria à espera de alguém que já disse que não. Passa-se ao
        // seguinte imediatamente, sem ele ter de refazer nada.
        $service = $candidate->service;

        if ($service && ! $this->isScheduled($service)) {
            $this->advanceImmediate($service);
        }
    }

    /**
     * Fluxo imediato: chama o próximo da lista quando o anterior não respondeu
     * ou recusou.
     *
     * É o fallback que torna a shortlist honesta. "Está livre" é uma previsão,
     * não uma promessa — e sem isto uma previsão errada custava ao cliente o
     * pedido inteiro.
     *
     * @return bool true se houve quem chamar; false se a lista se esgotou
     */
    public function advanceImmediate(Service $service): bool
    {
        return DB::transaction(function () use ($service) {
            $service = Service::whereKey($service->getKey())->lockForUpdate()->first();

            if (! $service || $service->status !== ServiceStatus::MATCHING) {
                return false;
            }

            // Se alguém já aceitou, não há nada a avançar: o cliente escolhe.
            if ($service->candidates()->accepted()->exists()) {
                return true;
            }

            $next = $service->candidates()
                ->where('status', CandidateStatus::SHORTLISTED)
                ->orderBy('rank')
                ->with('vendor')
                ->first();

            if (! $next) {
                // Só desiste se não houver mesmo mais ninguém. Falhar enquanto
                // alguém ainda tem a janela aberta seria deitar fora uma
                // resposta que pode estar a caminho.
                if (! $service->candidates()->live()->exists()) {
                    $this->fail($service);
                }

                return false;
            }

            $this->invite($next, $this->settings->vendor_response_seconds_immediate);

            // O cliente está no ecrã de espera: tem de saber que mudámos de
            // pessoa, senão vê "a contactar o João" enquanto se contacta outro.
            MatchingFallbackEvent::dispatch($service->customer_id, [
                'service_id' => $service->id,
                'candidate_id' => $next->id,
                'vendor_id' => $next->vendor_id,
                'vendor_name' => $next->vendor?->user?->name,
                'expires_at' => $next->refresh()->expires_at?->toIso8601String(),
            ]);

            return true;
        });
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

        $service->candidates()->stale()->update([
            'status' => CandidateStatus::EXPIRED,
            'responded_at' => now(),
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
     * Candidatos que o cliente pode escolher agora.
     *
     * No imediato são os da shortlist (ninguém foi notificado ainda); no
     * agendado, os que já disseram que sim. Nos dois casos, se só houver dois,
     * mostram-se dois.
     *
     * @return Collection<int, ServiceCandidate>
     */
    public function selectableFor(Service $service): Collection
    {
        return $service->candidates()
            ->whereIn('status', [CandidateStatus::SHORTLISTED, CandidateStatus::ACCEPTED])
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
        $window = $wave === 1 && $status === CandidateStatus::SHORTLISTED
            ? null
            : now()->addSeconds($this->settings->vendor_response_seconds_scheduled);

        return $ranked->map(function (RankedVendor $c) use ($service, $status, $wave, $window) {
            return ServiceCandidate::updateOrCreate(
                ['service_id' => $service->id, 'vendor_id' => $c->vendor->id],
                [
                    'rank' => $c->rank,
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
