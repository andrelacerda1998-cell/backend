<?php

namespace App\Services\Common\Services;

use App\Models\Service;
use App\Notifications\Vendor\NoShowPenaltyNotification;
use Illuminate\Support\Facades\DB;

/**
 * Declara a falta do técnico e cobra-lhe a penalização.
 *
 * QUEM DECLARA: a operação, e não o relógio. O `services:detect-no-show` já
 * deteta a não-comparência e alerta o backoffice 20 minutos depois da hora, mas
 * quem carrega no botão é uma pessoa — porque metade das "faltas" são o cliente
 * que não estava em casa ou uma morada errada, e tirar dinheiro a alguém por
 * engano não se desfaz com um rollback.
 *
 * O QUE FAZ:
 *  1. cobra ao técnico 50% do que ele ia receber (ver VendorNoShowPolicy);
 *  2. marca o serviço, o que serve de guarda de idempotência;
 *  3. cancela o serviço pelo caminho normal, que trata do reembolso ao cliente.
 *
 * A penalização é um `forceWithdraw`: a carteira PODE ficar negativa. Sem isso, a
 * penalização desaparecia quando a carteira estava vazia — precisamente para quem
 * falta mais vezes. A dívida sai dos ganhos seguintes.
 */
class RegisterVendorNoShow
{
    public function __construct(private Service $service) {}

    /**
     * @return int|null Penalização cobrada em cêntimos, ou null se o serviço não
     *                  estava em condições de ser penalizado (já foi, ou o técnico
     *                  chegou a aparecer).
     *
     * @throws \Throwable
     */
    public function handle(): ?int
    {
        $this->service->refresh();

        if (! VendorNoShowPolicy::isPenalizable($this->service)) {
            return null;
        }

        $amountForVendor = (int) abs($this->service->getRawOriginal('amount_for_vendor'));
        $penalty = VendorNoShowPolicy::penaltyAmount($amountForVendor);
        $vendorUser = $this->service->vendor?->user;

        if (! $vendorUser) {
            return null;
        }

        // Ledger e marcação juntos: se o débito falhar, o serviço não pode ficar
        // marcado como penalizado — perder-se-ia a penalização sem ninguém dar
        // por isso, e a marca impediria uma segunda tentativa.
        DB::transaction(function () use ($vendorUser, $penalty) {
            if ($penalty > 0) {
                $vendorUser->forceWithdraw($penalty, [
                    ...$this->service->getMetaProduct(),
                    'reason' => 'vendor_no_show',
                    'service_id' => $this->service->id,
                ]);
            }

            $this->service->vendor_no_show_at = now();
            $this->service->vendor_no_show_penalty = $penalty;
            $this->service->save();
        });

        // Fora da transação: cancelar toca no gateway (reembolso do cliente) e um
        // rollback não o desfaria. A penalização já está registada — se o
        // cancelamento falhar, fica um serviço por cancelar à mão, e não um
        // técnico penalizado duas vezes.
        (new CancelService($this->service))->customerCancel();

        // O técnico tem de saber porque é que o saldo mudou, e no mesmo dia:
        // descobrir um débito sem explicação semanas depois é como isto se
        // transforma numa reclamação.
        $vendorUser->notify(new NoShowPenaltyNotification($this->service, $penalty));

        return $penalty;
    }
}
