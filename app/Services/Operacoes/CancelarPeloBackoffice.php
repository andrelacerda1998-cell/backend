<?php

namespace App\Services\Operacoes;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\ServiceCanceledEvent as AvisoAoCliente;
use App\Events\Vendor\Services\ServiceCanceledEvent as AvisoAoTecnico;
use App\Models\Service;
use App\Models\User;
use App\Notifications\Customer\PedidoCanceladoPelaPiquetNotification;
use App\Notifications\Vendor\ServicoCanceladoPelaPiquetNotification;
use App\Services\Matching\MatchingService;
use Illuminate\Support\Facades\DB;
use RwInteractive\PayshopSdk\Enums\Payment\Status;

/**
 * A equipa da Piquet cancela um pedido, a partir do backoffice.
 *
 * Até aqui só o cliente e o técnico cancelavam, cada um pela app e cada um
 * com as suas regras: o cliente que desiste em cima da hora paga (a caminho,
 * no local), o técnico que larga um agendado perde fiabilidade. Nenhuma
 * dessas é a regra de quando é a Piquet a decidir — um pedido parado há
 * meses, um engano, um pedido que não se vai conseguir servir. Aqui:
 *
 *  - o cliente nunca paga: o que tiver pago volta todo (o ServiceObserver
 *    reembolsa ou liberta o cativo ao gravar CANCELED, como em todos os
 *    cancelamentos; uma falha do Payshop fica reportada e não prende o
 *    pedido);
 *  - o técnico não recebe nada e NÃO é penalizado (`vendor_canceled_at` fica
 *    vazio). Se foi ele que faltou, o caminho é "Dar como falta";
 *  - quem já sabia do pedido é avisado: o cliente sempre, o técnico atribuído,
 *    e os técnicos que estavam a ver o convite (pelo MatchingService).
 *
 * Só antes de o trabalho começar. Com o técnico no local (Arrived), já há
 * trabalho feito; terminado, fecha-se; por cobrar, há "tentar cobrar" e
 * "desistir e devolver".
 *
 * O motivo e quem cancelou ficam no backoffice (histórico da equipa, com o
 * nome de quem o fez); aqui o pedido fica com a justificação
 * `internal/services.cancel.backoffice`.
 */
final class CancelarPeloBackoffice
{
    public const JUSTIFICACAO = 'internal/services.cancel.backoffice';

    /** Os estados de onde a equipa pode cancelar. */
    public const CANCELAVEIS = [
        ServiceStatus::PENDING_REVIEW,
        ServiceStatus::MATCHING,
        ServiceStatus::AWAITING_PAYMENT,
        ServiceStatus::PENDING_3DS,
        ServiceStatus::PENDING,
        ServiceStatus::SCHEDULED,
        ServiceStatus::ACCEPTED,
    ];

    public function __construct(private readonly MatchingService $matching) {}

    /**
     * @throws \DomainException com a frase para a equipa, quando não se pode cancelar
     */
    public function __invoke(Service $service): void
    {
        $service->refresh();
        $estado = $service->status;

        if (! in_array($estado, self::CANCELAVEIS, true)) {
            throw new \DomainException(self::porqueNao($estado));
        }

        // O técnico só é avisado aqui se já tinha o pedido como dele (pago à
        // espera dele, agendado, aceite). Em seleção, quem estava a ver o
        // convite é avisado pelo MatchingService; antes de pagar, nenhum
        // técnico soube do pedido.
        $jaEraDele = in_array($estado, [ServiceStatus::SCHEDULED, ServiceStatus::ACCEPTED], true)
            || ($estado === ServiceStatus::PENDING && $service->payment_status === PaymentStatus::PAID);
        $tecnico = $jaEraDele ? $service->vendor?->user : null;

        match (true) {
            in_array($estado, [ServiceStatus::MATCHING, ServiceStatus::AWAITING_PAYMENT], true) => $this->emSelecao($service),
            $estado === ServiceStatus::PENDING_3DS,
            $estado === ServiceStatus::PENDING && $service->payment_status !== PaymentStatus::PAID => $this->antesDePagar($service),
            default => $this->comPedidoAssente($service),
        };

        $service->refresh();

        // Quem tinha o pedido nas mãos: a app dele atualiza-se já, e recebe
        // o aviso. Uma falha de aviso nunca desfaz o cancelamento.
        $this->avisar(function () use ($service) {
            AvisoAoCliente::dispatch($service->only(['id']));
        });
        $this->avisarPorPush($service->customerUser, new PedidoCanceladoPelaPiquetNotification($service));

        if ($tecnico) {
            $this->avisar(fn () => AvisoAoTecnico::dispatch($tecnico->id, $service));
            $this->avisarPorPush($tecnico, new ServicoCanceladoPelaPiquetNotification($service));
        }
    }

    /** Porque é que um pedido neste estado não se cancela daqui. */
    public static function porqueNao(?ServiceStatus $estado): string
    {
        return match ($estado) {
            ServiceStatus::ARRIVED => 'O técnico já está no local: com trabalho começado, isto não se cancela daqui.',
            ServiceStatus::FINISHED => 'O técnico já terminou: fecha o pedido, ou fala com o cliente se houver um problema.',
            ServiceStatus::CLOSED_PENDING_PAYMENT => 'Este pedido está por cobrar: usa "Tentar cobrar" ou "Desistir e devolver".',
            ServiceStatus::CLOSED => 'Este pedido já está fechado e pago. Para devolver o dinheiro, usa "Reembolsar".',
            default => 'Este pedido já acabou: não há nada para cancelar.',
        };
    }

    /**
     * À procura de técnico, ou à espera de o cliente escolher e pagar: fecha a
     * seleção e avisa os técnicos que estavam a ver o convite — a mesma
     * operação de quando o cliente desiste, com o mesmo lock.
     */
    private function emSelecao(Service $service): void
    {
        if (! $this->matching->cancelByCustomer($service)) {
            throw new \DomainException('O cliente está a meio do pagamento. Espera um minuto e volta a tentar.');
        }

        Service::whereKey($service->getKey())->update(['status_justification' => self::JUSTIFICACAO]);
    }

    /**
     * À espera do 3-D Secure ou de um MB Way por aprovar: o técnico ainda não
     * sabe do pedido. Primeiro pergunta-se ao Payshop se o cliente acabou de
     * pagar — se sim, não se cancela: o pedido seguiu, e quem o vai tratar é o
     * fluxo normal.
     */
    private function antesDePagar(Service $service): void
    {
        $ordem = $service->paymentOrder;

        if ($ordem) {
            try {
                $ordem->updateData();
            } catch (\Throwable $e) {
                // Payshop em baixo: decide-se com o que se sabe.
                report($e);
            }

            if (in_array($ordem->status, [Status::SUCCESS, Status::PENDING_CONFIRMATION], true)) {
                throw new \DomainException('O cliente acabou de pagar: o pedido seguiu. Atualiza e volta a ver.');
            }
        }

        $this->gravar($service, function (Service $s) {
            $s->payment_status = PaymentStatus::CANCELED;
            $s->pending_schedule_data = null;
        });
    }

    /**
     * Personalizado em análise, pago à espera do técnico, agendado ou aceite.
     * Grava CANCELED (o observer devolve o dinheiro) e liberta a marcação da
     * agenda do técnico, como a app e o Filament fazem ao cancelar.
     */
    private function comPedidoAssente(Service $service): void
    {
        $this->gravar($service);

        try {
            $service->schedule?->delete();
        } catch (\Throwable $e) {
            // O pedido já está cancelado; uma linha de agenda por apagar não muda isso.
            report($e);
        }
    }

    private function gravar(Service $service, ?\Closure $antes = null): void
    {
        DB::transaction(function () use ($service, $antes) {
            $travado = Service::whereKey($service->getKey())->lockForUpdate()->first();

            // Mudou entre o pedido do backoffice e agora (o cliente pagou, o
            // técnico chegou): não se cancela o que já não é o que se viu.
            if (! $travado || $travado->status !== $service->status) {
                throw new \DomainException('O pedido mudou entretanto de estado. Atualiza e volta a ver.');
            }

            if ($antes) {
                $antes($travado);
            }
            $travado->status = ServiceStatus::CANCELED;
            $travado->status_justification = self::JUSTIFICACAO;
            $travado->save();
        });
    }

    private function avisar(\Closure $aviso): void
    {
        try {
            $aviso();
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function avisarPorPush(?User $quem, object $notificacao): void
    {
        if (! $quem || $quem->trashed() || ! $quem->devices()->exists()) {
            return;
        }

        $this->avisar(fn () => $quem->notify($notificacao));
    }
}
