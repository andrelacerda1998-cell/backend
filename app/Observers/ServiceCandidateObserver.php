<?php

namespace App\Observers;

use App\Enums\Services\CandidateStatus;
use App\Models\ServiceCandidate;
use App\Services\Operacoes\RegistoDeEventos;

/**
 * Os convites e as respostas dos técnicos, no histórico do pedido.
 *
 * Só apanha o que passa pelo modelo. As passagens a LOST feitas em bloco no
 * MatchingService (`->candidates()->...->update()`) não disparam eventos:
 * quem "perdeu" vê-se pelo "escolhido" do outro e pelo estado do serviço.
 */
class ServiceCandidateObserver
{
    public function created(ServiceCandidate $c): void
    {
        if ($c->status === CandidateStatus::NOTIFIED) {
            $this->convidado($c);
        }
    }

    public function updated(ServiceCandidate $c): void
    {
        if (! $c->wasChanged('status')) {
            return;
        }

        if ($c->status === CandidateStatus::NOTIFIED) {
            $this->convidado($c);

            return;
        }

        if (in_array($c->status, [
            CandidateStatus::ACCEPTED, CandidateStatus::DECLINED, CandidateStatus::EXPIRED,
            CandidateStatus::SELECTED, CandidateStatus::LOST,
        ], true)) {
            $antes = $c->getOriginal('status');
            RegistoDeEventos::registar(
                $c->service_id,
                RegistoDeEventos::RESPOSTA,
                $antes instanceof CandidateStatus ? $antes->value : ($antes ? (string) $antes : null),
                $c->status->value,
                $c->vendor_id,
                ['onda' => $c->wave],
            );
        }
    }

    private function convidado(ServiceCandidate $c): void
    {
        RegistoDeEventos::registar(
            $c->service_id,
            RegistoDeEventos::CONVIDADO,
            null,
            CandidateStatus::NOTIFIED->value,
            $c->vendor_id,
            ['onda' => $c->wave, 'rank' => $c->rank, 'distancia_km' => $c->quoted_distance !== null ? (float) $c->quoted_distance : null],
        );
    }
}
