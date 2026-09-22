<?php

namespace App\Trait\Services;

use App\DTO\Services\AddressCoordinatesDTO;
use App\Enums\Services\AddressType;
use App\Models\Address;
use App\Models\Vendor;

trait HasVendorDistance
{
    private function calculateVendorDistance(Vendor $vendor, AddressCoordinatesDTO $userAddress): int
    {
        $address = $vendor->addresses()->where('address_type', AddressType::SCHEDULE_ADDRESS)->first();

        if (! $address) {
            $address = $vendor->addresses()->where('address_type', AddressType::FISCAL_ADDRESS)->first();
        }

        // Sem morada nenhuma nao ha distancia que calcular, e o acesso direto
        // a `$address->latitude` rebentava com 500. O `vendor_id` do
        // `/calculate` vem do cliente, por isso e alcancavel de fora: bastava
        // pedir o preco de um profissional que ainda nao completou o perfil
        // para o checkout responder "Something went wrong".
        if (! $address) {
            throw new \Exception('Vendor has no address to measure the distance from', 422);
        }

        return calculate_distance(
            $address->latitude,
            $address->longitude,
            $userAddress->latitude,
            $userAddress->longitude,
        );
    }

    private function calculateVendorDistanceInstantService(Vendor $vendor, AddressCoordinatesDTO|Address $userAddress): int
    {
        if ($vendor->currentLocation?->latitude && $vendor->currentLocation?->longitude) {
            $vendorLat = (float) $vendor->currentLocation->latitude;
            $vendorLng = (float) $vendor->currentLocation->longitude;
        } else {
            $fallback = $vendor->addresses()->where('address_type', AddressType::SCHEDULE_ADDRESS)->first()
                ?? $vendor->addresses()->where('address_type', AddressType::FISCAL_ADDRESS)->first();

            if (! $fallback) {
                throw new \Exception('Vendor location is not available');
            }

            $vendorLat = (float) $fallback->latitude;
            $vendorLng = (float) $fallback->longitude;
        }

        return calculate_distance(
            $vendorLat,
            $vendorLng,
            $userAddress->latitude,
            $userAddress->longitude,
        );
    }
}
