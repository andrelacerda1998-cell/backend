<?php

namespace App\Services\Matching;

use App\Enums\Services\CandidateStatus;
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

        return $this->persist($service, $batch, CandidateStatus::NOTIFIED, $wave);
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

                return false;
            }

            $candidate->update([
                'status' => CandidateStatus::ACCEPTED,
                'responded_at' => now(),
            ]);

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

            $fresh->update(['status' => CandidateStatus::SELECTED]);

            $service->candidates()
                ->whereKeyNot($fresh->getKey())
                ->live()
                ->update(['status' => CandidateStatus::LOST]);

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
            $service->candidates()->live()->update(['status' => CandidateStatus::LOST]);
            $service->update(['status' => ServiceStatus::MATCHING_FAILED]);
        });
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
            scheduledFor: $service->schedule?->scheduled_day
                ? \Carbon\Carbon::parse($service->schedule->scheduled_day)
                : null,
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
