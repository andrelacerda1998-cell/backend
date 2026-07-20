<?php

namespace App\DTO\Services;

use App\Models\Address;

readonly class AddressCoordinatesDTO
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {}

    public static function fromAddress(Address $address): self
    {
        return new self(
            latitude: (float) $address->latitude,
            longitude: (float) $address->longitude,
        );
    }
}
