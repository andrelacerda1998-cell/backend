<?php

namespace App\Services\Common;

use App\Models\User;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Spatie\Geocoder\Exceptions\CouldNotGeocode;
use Spatie\Geocoder\Facades\Geocoder;

class AddressService
{
    /**
     * @throws Exception
     */
    public function transformAddress($data, $geoAddress): array
    {
        $country = collect($geoAddress['address_components'])->firstWhere('types.0', 'country')?->long_name;

        if ($country !== 'Portugal') {
            throw new Exception('Only addresses from Portugal are accepted.');
        }

        $address_name = $data['address_name'];
        $state = collect($geoAddress['address_components'])->firstWhere('types.0', 'administrative_area_level_1')?->long_name;
        $city = collect($geoAddress['address_components'])->firstWhere('types.0', 'administrative_area_level_2')?->long_name;
        $street_number = collect($geoAddress['address_components'])->firstWhere('types.0', 'street_number')?->long_name;
        $street_name = collect($geoAddress['address_components'])->firstWhere('types.0', 'route')?->long_name
            ?? ($data['street_name'] ?? null);
        $postal_code = $data['postal_code'];
        $latitude = $geoAddress['lat'];
        $longitude = $geoAddress['lng'];
        $main_address = true;
        $name = $geoAddress['formatted_address'];

        return compact('address_name', 'state', 'city', 'country', 'street_number', 'street_name', 'postal_code', 'latitude', 'longitude', 'main_address', 'name');
    }

    public function getCoordinates($data)
    {
        $address = ($data['street_name'] ?? '').' '.($data['street_number'] ?? '').' '.($data['city'] ?? '').' '.($data['postal_code'] ?? '');

        return Cache::remember('geocoder-address-'.$address, now()->addDay(), function () use ($address) {
            try {
                return Geocoder::getCoordinatesForAddress($address);
            } catch (CouldNotGeocode $exception) {
                $message = $exception->getMessage();
                if (str_contains('You must enable Billing on the Google Cloud', $message)) {
                    $users = User::whereHas('roles', fn ($query) => $query->whereIn('name', ['super-admin', 'dev']))->get();
                    Notification::make()->title('Geocoding error')->body($message)->sendToDatabase($users);
                }

                return null;
            }
        });
    }
}
