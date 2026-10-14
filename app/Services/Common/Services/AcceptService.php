<?php

namespace App\Services\Common\Services;

use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\ServiceAcceptedEvent;
use App\Exceptions\Api\Vendor\Service\ServiceIsNotPending;
use App\Exceptions\Api\Vendor\Service\VendorIsBusy;
use App\Models\Service;
use App\Models\Vendor;
use App\Notifications\Customer\ServiceAcceptedNotification;

class AcceptService
{
    public function accept(Service $service): Service
    {
        // Lock + re-check inside a transaction so a double-submit can't transition twice and fire the
        // "accepted" notification more than once.
        return \DB::transaction(function () use ($service): Service {
            $locked = Service::whereKey($service->getKey())->lockForUpdate()->first();

            if (! $locked || $locked->status != ServiceStatus::PENDING) {
                throw new ServiceIsNotPending;
            }

            // Um imediato de cada vez. Com dois pedidos imediatos na mão, nada o
            // impedia de dizer que sim aos dois e de se comprometer a estar em
            // dois sítios agora. O outro pedido fica por responder e expira
            // sozinho (CancelJobWithoutReactionJob), com o dinheiro devolvido.
            //
            // A linha do profissional fica trancada até ao fim da transação:
            // sem isso, dois "aceitar" ao mesmo tempo liam os dois "livre".
            // Ordem dos locks: serviço e depois profissional, como no
            // MatchingService::select.
            if ($locked->scheduleIntent() === null && $locked->vendor_id) {
                $vendor = Vendor::whereKey($locked->vendor_id)->lockForUpdate()->first();

                if ($vendor?->ocupadoAgora($locked->getKey())) {
                    throw new VendorIsBusy;
                }
            }

            $service->status = ServiceStatus::ACCEPTED;
            $service->save();

            $this->notifyCustomer($service->formatDataForCustomer(), $service);

            return $service;
        });
    }

    public function acceptSchedule($service)
    {
        if ($service->status != ServiceStatus::PENDING) {
            throw new ServiceIsNotPending;
        }

        $service->status = ServiceStatus::SCHEDULED;
        $service->save();
    }

    private function notifyCustomer(array $serviceData, Service $service): void
    {
        ServiceAcceptedEvent::dispatch($service->customer, $serviceData);
        $service->customer->notify(new ServiceAcceptedNotification($service));
    }
}
