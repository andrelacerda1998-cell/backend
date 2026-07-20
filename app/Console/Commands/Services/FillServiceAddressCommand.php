<?php

namespace App\Console\Commands\Services;

use App\Enums\Services\ServiceStatus;
use App\Models\Address;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Service;
use App\Models\Vendor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FillServiceAddressCommand extends Command
{
    protected $signature = 'services:fill-address';

    protected $description = 'Fill service address information';

    public function handle(): void
    {
        $services = Service::query()->whereNull('address')->get();

        $this->info('Filling service address information');
        $this->info('Found ' . $services->count() . ' services without address');

        foreach ($services as $service) {
            $customer_id = $service->customer_id;
            $address = Address::query()
                ->where('user_id', $customer_id)
                ->first();

            $this->info('Saving Service ID: ' . $service->id);

            $service->address = [
                'city' => $address->city ?? null,
                'name' => $address->name ?? null,
                'state' => $address->state ?? null,
                'country' => $address->country ?? null,
                'latitude' => $address->latitude ?? null,
                'longitude' => $address->longitude ?? null,
                'postal_code' => $address->postal_code ?? null,
                'street_name' => $address->street_name ?? null,
                'street_number' => $address->street_number ?? null,
                'additional_info' => $address->additional_info ?? null,
            ];
            $service->save();
        }
    }
}
