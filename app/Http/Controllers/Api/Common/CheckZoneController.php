<?php

namespace App\Http\Controllers\Api\Common;

use App\Http\Controllers\Controller;
use App\Models\GeneralSettings\AllowedZone;
use Illuminate\Http\Request;
use Spatie\Geocoder\Facades\Geocoder;

class CheckZoneController extends Controller
{
    public function __invoke(Request $request)
    {
        $request->validate([
            'latitude'     => 'nullable|numeric',
            'longitude'    => 'nullable|numeric',
            'street_name'  => 'nullable|string|max:255',
            'street_number'=> 'nullable|string|max:50',
            'postal_code'  => 'nullable|string|max:20',
            'city'         => 'nullable|string|max:255',
        ]);

        $municipality = $this->resolveMunicipality($request);

        if (! $municipality) {
            return response()->json(['data' => ['allowed_by_zone' => false]]);
        }

        $allowed = AllowedZone::whereRaw('LOWER(city) = LOWER(?)', [$municipality])->exists();

        return response()->json(['data' => ['allowed_by_zone' => $allowed]]);
    }

    private function resolveMunicipality(Request $request): ?string
    {
        $lat = $request->input('latitude');
        $lng = $request->input('longitude');

        // Coords → reverse geocode (most accurate)
        if ($lat !== null && $lng !== null) {
            $municipality = $this->municipalityFromCoords((float) $lat, (float) $lng);
            if ($municipality) {
                return $municipality;
            }
        }

        // Full address → forward geocode (disambiguates same-name localities)
        $addressString = $this->buildAddressString($request);
        if ($addressString) {
            $municipality = $this->municipalityFromAddress($addressString);
            if ($municipality) {
                return $municipality;
            }
        }

        // Fallback: city direct match
        $city = $request->input('city');
        if ($city) {
            if (AllowedZone::whereRaw('LOWER(city) = LOWER(?)', [$city])->exists()) {
                return $city;
            }

            // Last resort: geocode city name alone
            return $this->municipalityFromAddress($city.', Portugal');
        }

        return null;
    }

    private function buildAddressString(Request $request): ?string
    {
        $parts = array_filter([
            trim(($request->input('street_name') ?? '').' '.($request->input('street_number') ?? '')),
            $request->input('postal_code'),
            $request->input('city'),
            'Portugal',
        ]);

        if (count($parts) < 2) {
            return null;
        }

        return implode(', ', $parts);
    }

    private function municipalityFromCoords(float $lat, float $lng): ?string
    {
        try {
            $results = Geocoder::setLanguage('pt')->getAllAddressesForCoordinates($lat, $lng);

            foreach ($results as $geo) {
                $municipality = collect($geo['address_components'] ?? [])
                    ->firstWhere('types.0', 'administrative_area_level_2')
                    ?->long_name;

                if ($municipality) {
                    return $municipality;
                }
            }
        } catch (\Exception $e) {
            //
        }

        return null;
    }

    private function municipalityFromAddress(string $address): ?string
    {
        try {
            $geo = Geocoder::setLanguage('pt')->getCoordinatesForAddress($address);

            return collect($geo['address_components'] ?? [])
                ->firstWhere('types.0', 'administrative_area_level_2')
                ?->long_name;
        } catch (\Exception $e) {
            return null;
        }
    }
}