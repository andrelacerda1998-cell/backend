<?php

namespace App\Services\Common\Services;

use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\ServiceAcceptedEvent;
use App\Exceptions\Api\Vendor\Service\ServiceIsNotPending;
use App\Models\Service;
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
