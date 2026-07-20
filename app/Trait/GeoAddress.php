<?php

namespace App\Trait;

use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Spatie\Geocoder\Facades\Geocoder;

trait GeoAddress
{
    protected function getCoordinates($data)
    {
        $address = ($data['street_name'] ?? '').' '.($data['street_number'] ?? '').' '.($data['city'] ?? '').' '.($data['postal_code'] ?? '');
        $cacheKey = 'geocoder-address-'.$address;

        // Só respostas VÁLIDAS são cacheadas (ver abaixo). Uma falha transitória não pode
        // envenenar a cache por 24h, o que devolvia '' repetidamente e rebentava o fluxo.
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $result = Geocoder::getCoordinatesForAddress($address);
        } catch (\Spatie\Geocoder\Exceptions\CouldNotGeocode $exception) {
            $message = $exception->getMessage();

            // Ordem correta dos argumentos: haystack ($message) primeiro, needle depois —
            // antes estava invertida e o alerta de billing nunca disparava.
            if (str_contains($message, 'You must enable Billing on the Google Cloud')) {
                $users = User::whereHas('roles', fn ($query) => $query->whereIn('name', ['super-admin', 'dev']))->get();
                Notification::make()->title('Geocoding error')->body($message)->sendToDatabase($users);
            }

            return '';
        }

        // Cacheia apenas quando o geocode devolveu coordenadas utilizáveis.
        if (is_array($result) && isset($result['lat'], $result['lng'])) {
            Cache::put($cacheKey, $result, now()->addDay());
        }

        return $result;
    }

    protected function transformAddress($geoAddress): array
    {
        $country = collect($geoAddress['address_components'])->firstWhere('types.0', 'country')?->long_name;

        $address_name = $geoAddress['formatted_address'];
        $state = collect($geoAddress['address_components'])->firstWhere('types.0', 'administrative_area_level_1')?->long_name;
        $city = collect($geoAddress['address_components'])->firstWhere('types.0', 'administrative_area_level_2')?->long_name;
        $street_number = collect($geoAddress['address_components'])->firstWhere('types.0', 'street_number')?->long_name;
        $street_name = collect($geoAddress['address_components'])->firstWhere('types.0', 'route')?->long_name;
        $postal_code = collect($geoAddress['address_components'])->firstWhere('types.0', 'postal_code')?->long_name;
        $latitude = $geoAddress['lat'];
        $longitude = $geoAddress['lng'];
        $main_address = true;
        $name = $geoAddress['formatted_address'];

        return compact('address_name', 'state', 'city', 'country', 'street_number', 'street_name', 'postal_code', 'latitude', 'longitude', 'main_address', 'name');
    }
}
