<?php

namespace App\Http\Controllers\Api\Customer\Services\traits;

use App\Events\Vendor\Schedule\ServiceScheduledEvent;
use App\Events\Vendor\Services\CreateServiceEvent;
use App\Models\Service;
use App\Models\Vendor;
use App\Notifications\Vendor\NewScheduledServiceNotification;
use App\Notifications\Vendor\NewServiceAvailableNotification;
use App\Services\RateService;

trait NotifyVendor
{
    private function notifyVendor(Service $service, Vendor $vendor)
    {
        $vendorUserId = $vendor->user_id;
        $rateService = app(RateService::class);
        $service = $service->load('serviceType');
        $timeService = $service->serviceType->time ?? 10;
        $hourlyRate = $vendor->getRawOriginal('price_rate');

        // amount_for_vendor é definido na criação do serviço, consistente com o amount do cliente
        // (mesma distância). Aqui só se faz backfill de registos legados onde ainda esteja nulo —
        // nunca sobrescrever o valor consistente por uma medição posterior/divergente.
        if (is_null($service->amount_for_vendor)) {
            $gpsDistance = $vendor->calculateDistance($service->address);
            $service->amount_for_vendor = $this->calculatePriceForVendor($rateService, $hourlyRate, $timeService, $gpsDistance);
            $service->save();
        }

        // Payload usa a distância gravada no serviço (regra de pricing: agendado = morada de agenda,
        // imediato = GPS), para o profissional ver a mesma distância pela qual é pago.
        $serviceData = $this->prepareServiceData($service, $service->distance);

        if ($service->schedule && ($vendor->scheduleAvailable()->first()?->auto_accept ?? false)) {
            $service->loadMissing('schedule.serviceType');
            $schedule = $service->schedule;

            ServiceScheduledEvent::dispatch($vendorUserId, ['id' => $schedule->id, 'service_id' => $service->id]);
            $vendor->user->notifyNow(new NewScheduledServiceNotification($schedule));

            return;
        }

        if (! $service->schedule) {
            CreateServiceEvent::dispatch($vendorUserId, $serviceData);
        }

        $vendor->user->notifyNow(new NewServiceAvailableNotification($service));
    }

    private function formatServiceResponse($service, $validationUrl = null)
    {
        return [
            'service' => [
                'status' => $service->status,
                'id' => $service->id,
                'created_at' => $service->created_at,
            ],
            'payment_validationUrl' => $validationUrl,
        ];
    }

    private function calculatePriceForVendor(RateService $rateService, float $hourlyRate, int $timeService, float $distance): float
    {
        return $rateService->calculateForVendor($hourlyRate, $timeService, $distance);
    }

    private function prepareServiceData(Service $service, float $distance): array
    {
        $lang = $service->vendor->user->language;

        return [
            'id' => $service->id,
            'status' => $service->status,
            'amount_for_vendor' => $service->amount_for_vendor,
            'distance' => $distance,
            'service_type' => [
                'time' => $service->serviceType->time,
                'name' => $service->serviceType->getTranslation('name', $lang),
            ],
            'service_area' => [
                'name' => $service->serviceType->operationArea->getTranslation('name', $lang),
            ],
            'time' => $service->serviceType->time,
            'address' => [
                'street_name' => $service->address['street_name'],
                'postal_code' => $service->address['postal_code'],
                'city' => $service->address['city'],
                'state' => $service->address['state'],
                'country' => $service->address['country'],
            ],
            'created_at' => $service->created_at,
            'updated_at' => $service->updated_at,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
