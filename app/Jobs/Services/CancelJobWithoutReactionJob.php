<?php

namespace App\Jobs\Services;

use App\Enums\Services\ServiceStatus;
use App\Events\Common\Services\ServiceTimeoutEvent as CommonServiceTimeoutEvent;
use App\Events\Vendor\Services\ServiceTimeoutEvent as VendorServiceTimeoutEvent;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class CancelJobWithoutReactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly Service $service) {}

    public function handle(): void
    {
        // Transição sob lockForUpdate + re-check do estado: corre em paralelo com o
        // AcceptServiceController (o vendor a aceitar). Sem lock, o read PENDING + save CANCELED
        // podia sobrepor um ACCEPTED já gravado pelo controller (lost update). O lock serializa
        // as duas escritas; se o serviço já não estiver PENDING, o job não faz nada.
        $canceled = DB::transaction(function () {
            $locked = Service::whereKey($this->service->id)->lockForUpdate()->first();

            if (! $locked) {
                return false;
            }

            $locked->loadMissing('schedule');
            if ($locked->schedule && ! $locked->schedule->is_pending) {
                return false;
            }

            if ($locked->status !== ServiceStatus::PENDING) {
                return false;
            }

            $locked->status = ServiceStatus::CANCELED;
            $locked->status_justification = 'The vendor has not provided a timely response to the job request.';
            $locked->save();

            return true;
        });

        if (! $canceled) {
            return;
        }

        // Efeitos externos (eventos/pushes) DEPOIS do commit, sobre o estado fresco.
        $this->service->refresh();

        $serviceData = [
            'id' => $this->service->id,
            'status' => $this->service->status,
            'distance' => $this->service->distance,
            'customer_notes' => $this->service->customer_notes,
            'vendor_notes' => $this->service->vendor_notes,
            'amount' => $this->service->amount,
            // Relações nullable: se o cliente virou vendor (customer() filtra
            // whereDoesntHave('vendor')) ou foi apagado, sem ?-> o job rebentava DEPOIS de
            // gravar CANCELED e os eventos de timeout nunca eram dispatchados.
            'customer' => $this->service->customer?->only('name', 'phone', 'email'),
            'vendor' => [
                'user' => $this->service->vendor?->user?->only('name', 'phone', 'email'),
                'price_rate' => $this->service->vendor?->price_rate,
            ],
            'service_type' => [
                'id' => $this->service->serviceType?->id,
                'name' => $this->service->serviceType?->name,
                'time' => $this->service->serviceType?->time,
                'operation_area' => $this->service->serviceType?->operationArea?->only('id', 'name'),
            ],
            'address' => $this->service->address ? [
                'name' => $this->service->address['name'],
                'additional_info' => $this->service->address['additional_info'],
                'latitude' => $this->service->address['latitude'],
                'longitude' => $this->service->address['longitude'],
            ] : null,
        ];

        VendorServiceTimeoutEvent::dispatch($this->service);
        CommonServiceTimeoutEvent::dispatch($serviceData);

        //  Disabled by customer
        // $vendor = $this->service->vendor;
        // $vendor->status = \App\Enums\Vendors\StatusVendor::OFFLINE;
        // $vendor->save();
    }
}
