<?php

namespace App\Enums\Services;

enum ServiceStatus: string
{
    case PENDING = 'Pending';
    // À procura de profissional: o serviço existe, ainda sem vendor_id e sem
    // pagamento. Fora do conjunto de "serviço aberto" — um pedido por atribuir
    // não pode bloquear um profissional de aceitar outros (ver docs/matching.md).
    case MATCHING = 'Matching';
    // Pedido personalizado a espera de o backoffice definir tempo e categorias.
    // So depois passa a MATCHING e os convites saem.
    case PENDING_REVIEW = 'PendingReview';
    // Um profissional aceitou e o cliente escolheu-o; falta o checkout. Também
    // fora do "serviço aberto": ainda não há dinheiro nem compromisso firme.
    case AWAITING_PAYMENT = 'AwaitingPayment';
    // Ninguém aceitou, ou todos recusaram, ou o checkout caducou; terminal.
    case MATCHING_FAILED = 'MatchingFailed';
    // Awaiting 3DS credit-card validation; intentionally outside the "open service" set so it does not block new requests
    case PENDING_3DS = 'Pending3DS';
    case CANCELED = 'Canceled';
    case ACCEPTED = 'Accepted';
    // After service have been fully completed
    case CLOSED = 'Closed';
    // Customer confirmed the service (close) but the payment capture failed; the vendor has NOT
    // been paid. Awaiting a manual capture retry (backoffice) that will settle and move to CLOSED.
    case CLOSED_PENDING_PAYMENT = 'ClosedPendingPayment';
    case REFUSED = 'Refused';
    // After service have been finished by the vendor but not yet by the customer
    case FINISHED = 'Finished';
    case ARRIVED = 'Arrived';
    case SCHEDULED = 'Scheduled';
    // Archived by an admin; intentionally outside the "open service" set so it does not block new requests
    case ARCHIVED = 'Archived';
    // MBWay payment refused by the customer in their bank app; terminal, outside the "open service" set
    case REFUSED_MBWAY = 'RefusedMbway';
    // Customer never confirmed the MBWay push in time (~4 min); terminal, outside the "open service" set
    case EXPIRED_MBWAY = 'ExpiredMbway';
    // Customer canceled from the MBWay waiting screen BEFORE the payment was confirmed — the vendor
    // was never notified of this service, so no cancellation notice is sent; terminal
    case CANCELED_MBWAY = 'CanceledMbway';
    // Card 3DS never confirmed within the reaper window (services:expire-pending-3ds); terminal.
    // Intentionally OUTSIDE the ServiceObserver refund list — a stuck 3DS was never captured, so
    // there is no remote hold to release; the remote order (if any) expires on its own. The reaper
    // does the local refund (wallet credit + voucher) itself.
    case EXPIRED_3DS = 'Expired3DS';

    /**
     * «O profissional levou este serviço até ao fim.»
     *
     * UM SÓ SÍTIO, e é esta a razão de existir deste método. A regra da AT
     * contava `CLOSED + CLOSED_PENDING_PAYMENT + ARCHIVED` (ver
     * `Vendor::completedServices`) enquanto o cartão «Sem serviços» do
     * backoffice contava só `CLOSED`. O mesmo profissional aparecia como «já
     * fez serviços» para a AT e como «nunca fez nenhum» no ecrã ao lado --
     * duas respostas certas para duas perguntas que deviam ser a mesma.
     *
     * PORQUE É QUE OS TRÊS ESTADOS CONTAM:
     *
     *  - `CLOSED`: o caso normal, serviço feito e pago.
     *  - `CLOSED_PENDING_PAYMENT`: o cliente confirmou, o trabalho ESTÁ feito;
     *    o que falhou foi a captura do pagamento, e isso é um problema nosso
     *    com o banco, não trabalho por fazer. Dizer a quem o fez que não fez
     *    nada seria mentira.
     *  - `ARCHIVED`: arquivado por um administrador depois do facto. Arquivar
     *    é arrumar, não é desfazer.
     *
     * ONDE *NÃO* SE USA ISTO, de propósito:
     *
     *  - dinheiro (`VendorPaymentController`, `PaymentOrderController`, as
     *    somas do «top por receita»). Aí `CLOSED_PENDING_PAYMENT` é
     *    exactamente o que o nome diz -- ainda não houve captura -- e somá-lo
     *    inflacionava valores recebidos com dinheiro que não entrou;
     *  - avaliações (`updateRatting`, `VendorRankingService`). Essas filtram
     *    por `rating_by_customer` e mexem na nota pública; mudar o conjunto
     *    mudava notas de gente real, e é uma decisão à parte desta.
     *
     * @return array<int, self>
     */
    public static function concluidos(): array
    {
        return [self::CLOSED, self::CLOSED_PENDING_PAYMENT, self::ARCHIVED];
    }
}
