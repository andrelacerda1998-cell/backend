<?php

namespace App\Services\Common\Services;

use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Models\Service;
use App\Notifications\Customer\Mbway\PaymentRefusedNotification;

/**
 * Desfechos de pagamento MBWay partilhados entre o MbwayPaymentCheckJob (polling do backend),
 * o checkPaymentStatus (polling/force-check da app) e o CancelServiceController (re-verificação
 * antes de cancelar) — um único sítio para as transições, para os três nunca divergirem.
 */
class MbwayPaymentResolver
{
    /**
     * Estados em que o serviço nunca pode ser revivido por uma verificação de pagamento.
     */
    public const TERMINAL_STATUSES = [
        ServiceStatus::CANCELED,
        ServiceStatus::CANCELED_MBWAY,
        ServiceStatus::REFUSED,
        ServiceStatus::REFUSED_MBWAY,
        ServiceStatus::EXPIRED_MBWAY,
        ServiceStatus::EXPIRED_3DS,
        ServiceStatus::ARCHIVED,
        ServiceStatus::CLOSED,
        ServiceStatus::CLOSED_PENDING_PAYMENT,
        // Fluxo de seleção que expirou sem pagamento. Sem isto, um MBWay
        // confirmado tarde ressuscitava um pedido já desistido, com os
        // profissionais todos dispensados.
        ServiceStatus::MATCHING_FAILED,
    ];

    public static function isTerminal(Service $service): bool
    {
        return in_array($service->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Cliente recusou o push MBWay no banco. Gravar o estado terminal dispara o
     * ServiceObserver, que liberta/reembolsa o hold e põe payment_status = REFUNDED.
     */
    public function markRefused(Service $service): void
    {
        $service->status = ServiceStatus::REFUSED_MBWAY;
        $service->status_justification = 'internal/services.mbway.refused';
        $service->pending_schedule_data = null;
        $service->save();

        $service->customer->notify(new PaymentRefusedNotification($service));
    }

    /**
     * Pagamento confirmado no Payshop: apenas a transição de estado. A materialização da
     * agenda/notificação ao vendor (MaterializePendingSchedule) fica a cargo do caller,
     * FORA de qualquer transação com lock — dispara eventos/pushes que não devem correr
     * antes do commit.
     */
    public function markPaid(Service $service): void
    {
        $service->payment_status = PaymentStatus::PAID;

        if ($service->status === ServiceStatus::PENDING_3DS || $service->status === ServiceStatus::AWAITING_PAYMENT) {
            // Fluxo de seleção com pagamento assíncrono (3DS/MBWay): o
            // profissional aceitou ANTES do pagamento, por isso não há nada à
            // espera de confirmação dele — vai direto a Accepted. Um agendado
            // passa por Pending de propósito: é a materialização da agenda que
            // o leva a Scheduled, e pôr Scheduled aqui fazia-a rejeitar.
            $alreadyAccepted = MaterializePendingSchedule::vendorAlreadyAccepted($service);

            $service->status = ($alreadyAccepted && ! $service->pending_schedule_data)
                ? ServiceStatus::ACCEPTED
                : ServiceStatus::PENDING;
        }

        $service->save();
    }
}
