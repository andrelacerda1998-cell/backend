<?php

namespace App\Trait\Customer;

use App\Trait\GeoAddress;
use Spatie\Geocoder\Facades\Geocoder;

/**
 * Normaliza uma morada do cliente (validação + geocode) para gravar. Extraído
 * do UpdateAddressController para ser reutilizado pelo CRUD multi-morada — a
 * mesma morada é transformada da mesma forma quer venha da edição única quer da
 * lista de moradas.
 *
 * `main_address` vem sempre a true do transform (herança do fluxo antigo de
 * morada única); quem chama controla a principal e sobrepõe conforme a regra.
 */
trait ResolvesCustomerAddress
{
    use GeoAddress;

    /**
     * @throws \RuntimeException quando a morada não é válida/geocodificável
     */
    protected function resolveAddressData(array $data): array
    {
        $hasCoordinates = isset($data['latitude'], $data['longitude'])
            && $data['latitude'] !== null
            && $data['longitude'] !== null;

        $geoAddress = $hasCoordinates ? null : $this->getCoordinates($data);

        if (! $hasCoordinates && (! is_array($geoAddress)
            || empty($geoAddress['address_components'] ?? null)
            || ! isset($geoAddress['lat'], $geoAddress['lng']))) {
            throw new \RuntimeException('Address is invalid');
        }

        return $this->transformAddress($data, $geoAddress);
    }

    private function transformAddress($data, $geoAddress)
    {
        // Coordenadas vindas da app (Place Details): usam-se diretamente.
        if ($geoAddress === null) {
            $country = $data['country'] ?? 'Portugal';

            if ($country !== 'Portugal') {
                throw new \Exception('Only addresses from Portugal are accepted.');
            }

            return [
                'address_name' => $data['address_name'] ?? null,
                // addresses.state é NOT NULL; a app pode não enviar state — fallback ''.
                'state' => $data['state'] ?? '',
                'city' => $data['city'] ?? null,
                'country' => $country,
                'municipality' => $this->resolveMunicipality($data),
                'street_number' => $data['street_number'] ?? null,
                'street_name' => $data['street_name'] ?? null,
                'additional_info' => $data['additional_info'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'main_address' => true,
                'name' => trim(($data['street_name'] ?? '').' '.($data['street_number'] ?? '').', '.($data['postal_code'] ?? '').' '.($data['city'] ?? '')),
            ];
        }

        $country = collect($geoAddress['address_components'])->firstWhere('types.0', 'country')?->long_name;

        if ($country !== 'Portugal') {
            throw new \Exception('Only addresses from Portugal are accepted.');
        }

        $address_name = $data['address_name'] ?? null;
        $state = collect($geoAddress['address_components'])->firstWhere('types.0', 'administrative_area_level_1')?->long_name;
        $city = collect($geoAddress['address_components'])->firstWhere('types.0', 'administrative_area_level_2')?->long_name;
        $street_number = collect($geoAddress['address_components'])->firstWhere('types.0', 'street_number')?->long_name;
        $street_name = collect($geoAddress['address_components'])->firstWhere('types.0', 'route')?->long_name;
        $municipality = collect($geoAddress['address_components'] ?? [])->firstWhere('types.0', 'administrative_area_level_2')?->long_name;
        $additional_info = $data['additional_info'] ?? null;
        $postal_code = $data['postal_code'];
        $latitude = $geoAddress['lat'];
        $longitude = $geoAddress['lng'];
        $main_address = true;
        $name = $geoAddress['formatted_address'];

        return compact('address_name', 'municipality', 'state', 'city', 'country', 'street_number', 'street_name', 'additional_info', 'postal_code', 'latitude', 'longitude', 'main_address', 'name');
    }

    private function resolveMunicipality(array $data): ?string
    {
        $lat = $data['latitude'] ?? null;
        $lng = $data['longitude'] ?? null;

        if ($lat && $lng) {
            try {
                $geo = Geocoder::setLanguage('pt')->getAddressForCoordinates((float) $lat, (float) $lng);
                $municipality = collect($geo['address_components'] ?? [])
                    ->firstWhere('types.0', 'administrative_area_level_2')
                    ?->long_name;

                if ($municipality) {
                    return $municipality;
                }
            } catch (\Exception $e) {
                // fall through
            }
        }

        return $data['city'] ?? null;
    }
}
