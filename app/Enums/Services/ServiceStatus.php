<?php

namespace App\Enums\Services;

enum ServiceStatus: string
{
    case PENDING = 'Pending';
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
}
