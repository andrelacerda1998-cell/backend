<?php

namespace App\DTO\Services;

use App\Models\Service;
use App\Models\User;
use Carbon\Carbon;

readonly class ServiceRequestedData
{
    public function __construct(
        public int $id,
        public ?int $schedule_id,
        public float $amount,
        public float $distance,
        public ?float $amount_for_vendor,
        public string $status,
        public array $vendor,
        public array $customer,
        public ?array $address,
        public array $service_area,
        public array $service_type,
        public ?array $schedule,
        public ?string $date_label,
        public Carbon $updated_at,
        public Carbon $server_time,
        public int $created_timestamp,
        public int $updated_timestamp,
    ) {}

    public static function fromArray(Service $service, User $user): self
    {
        return new self(
            id: $service->id,
            schedule_id: $service->schedule?->id,
            amount: $service->amount,
            distance: $service->distance,
            amount_for_vendor: $service->amount_for_vendor,
            status: $service->status->value,
            vendor: [
                'username' => $user->vendor->username,
                'user' => [
                    'name' => $user->vendor->user->name,
                ],
            ],
            customer: $service->customer?->only(['id', 'name', 'address']),
            address: ['name' => $service->address?$service->address['city'].', '.ucfirst($service->address['state'])  : null],
            service_area: $service->serviceType->operationArea->only(['name']),
            service_type: $service->serviceType->only(['id', 'time', 'name']),
            schedule: $service->schedule?->only('scheduled_day', 'scheduled_time_start', 'scheduled_time_end'),
            date_label: $service->date_label,
            updated_at: $service->updated_at,
            server_time: now(),
            created_timestamp: $service->created_at->timestamp,
            updated_timestamp: $service->updated_at->timestamp,
        );
    }
}
