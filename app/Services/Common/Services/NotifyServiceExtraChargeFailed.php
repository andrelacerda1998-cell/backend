<?php

namespace App\Services\Common\Services;

use App\Models\Service;
use App\Models\ServiceExtra;
use App\Notifications\Customer\ServiceExtraChargeFailedNotification as CustomerChargeFailedNotification;
use App\Notifications\Vendor\ServiceExtraChargeFailedNotification as VendorChargeFailedNotification;

/**
 * Avisar técnico (não vai receber o extra) e cliente (a cobrança que aprovou falhou).
 * Extraído por ser chamado a partir de 3 pontos: aprovação (ServiceExtrasController),
 * fecho do serviço (CloseService) e o retorno do 3DS (Validation\SuccessController/
 * FailureValidationController).
 */
class NotifyServiceExtraChargeFailed
{
    public function handle(Service $service, ServiceExtra $extra): void
    {
        try {
            $vendorUser = $service->vendor?->user;
            if ($vendorUser && ! $vendorUser->trashed() && $vendorUser->devices()->exists()) {
                $vendorUser->notify(new VendorChargeFailedNotification($service, $extra));
            }

            $service->customer?->notify(new CustomerChargeFailedNotification($service, $extra));
        } catch (\Throwable $e) {
            report($e); // falha de push nunca quebra o fluxo que chamou isto
        }
    }
}
