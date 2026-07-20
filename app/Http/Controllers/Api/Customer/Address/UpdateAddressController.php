<?php

namespace App\Http\Controllers\Api\Customer\Address;

use App\Enums\Services\AddressType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\Address\UpdateRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Trait\GeoAddress;
use Spatie\Geocoder\Facades\Geocoder;

class UpdateAddressController extends Controller
{
    use GeoAddress;

    public function __invoke(UpdateRequest $request)
    {
        $data = $request->validated();

        $user = auth('api')?->user();

        // A rota é guest-accessible (withoutMiddleware auth:api) para o checkout de convidado.
        // Porém, se o pedido trouxer um Bearer token que já não é válido (expirado/revogado),
        // auth('api')->user() devolve null silenciosamente: a morada não era persistida e o
        // cliente autenticado recebia 200 "morada atualizada" + allowed_by_zone null, sendo
        // indevidamente empurrado para blocked-by-zone. Distinguir token-inválido (401) de
        // convidado genuíno (sem token → segue o fluxo normal).
        if ($user === null && $request->bearerToken()) {
            return new ApiErrorResponse(new \Exception('Unauthenticated.'), 'Unauthenticated.', 401);
        }

        $customer = $user?->isCustomer() ? $user : null;

        // Use coordinates provided by the app (from Place Details) when available,
        // otherwise fall back to server-side geocoding to avoid coordinate mismatch.
        $hasCoordinates = isset($data['latitude'], $data['longitude'])
            && $data['latitude'] !== null
            && $data['longitude'] !== null;

        $geoAddress = $hasCoordinates ? null : $this->getCoordinates($data);

        // Geocoding falhado devolve '' (não array). Sem esta guarda, transformAddress faz
        // collect(''['address_components']) → TypeError (\Error, não apanhado por catch \Exception)
        // → 500. Espelha a proteção que o lado vendor já tem (Api/Vendor/AddressController::update).
        if (! $hasCoordinates && (! is_array($geoAddress)
            || empty($geoAddress['address_components'] ?? null)
            || ! isset($geoAddress['lat'], $geoAddress['lng']))) {
            return new ApiErrorResponse(new \Exception('Could not geocode address.'), 'Address is invalid', 400);
        }

        try {
            $addressData = $this->transformAddress($data, $geoAddress);
        } catch (\Throwable $e) {
            return new ApiErrorResponse($e, 'Address is invalid', 400);
        }

        if ($customer) {
            $customer->addresses()->updateOrCreate([], [
                ...$addressData,
                'address_type' => AddressType::HOUSE_ADDRESS,
            ]);
        }

        return new ApiSuccessResponse(['address' => $addressData, 'allowed_by_zone' => $user?->allowedByZone]);
    }

    private function transformAddress($data, $geoAddress)
    {
        // When coordinates come from the app (Place Details API), use them directly.
        if ($geoAddress === null) {
            $country = $data['country'] ?? 'Portugal';

            if ($country !== 'Portugal') {
                throw new \Exception('Only addresses from Portugal are accepted.');
            }

            return [
                'address_name' => $data['address_name'] ?? null,
                // addresses.state é NOT NULL; a app não envia state neste ecrã (ver
                // issues/pending/2026-07-12-update-address-app-nao-envia-state.md),
                // por isso o fallback é '' como no fluxo guest — nunca null.
                'state' => $data['state'] ?? '',
                'city' => $data['city'] ?? null,
                'country' => $country,
                // A app envia a localidade em city ("Costa de Caparica"); a validação de zona
                // compara municipality com concelhos da allowed_zone ("Almada"), por isso o
                // concelho tem de vir do reverse-geocode — igual ao GuestRegisterController.
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
