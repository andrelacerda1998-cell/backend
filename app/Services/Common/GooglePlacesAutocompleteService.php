<?php

namespace App\Services\Common;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GooglePlacesAutocompleteService
{
    private const CACHE_TTL_HOURS = 24;

    private const DETAILS_CACHE_TTL_DAYS = 7;

    private const FAILURE_CACHE_TTL_SECONDS = 120;

    // Sem timeouts explícitos, uma lentidão do Google prende um worker do FPM
    // indefinidamente e o pool esgota (502/504 para todo o backend).
    private const CONNECT_TIMEOUT_SECONDS = 2;

    private const REQUEST_TIMEOUT_SECONDS = 5;

    private const AUTOCOMPLETE_URL = 'https://maps.googleapis.com/maps/api/place/autocomplete/json';

    private const DETAILS_URL = 'https://maps.googleapis.com/maps/api/place/details/json';

    public function getAutocomplete(string $input): ?array
    {
        $cacheKey = 'places_autocomplete:'.md5(strtolower(trim($input)));

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $predictions = $this->fetchAutocompleteFromGoogle($input);

        if ($predictions === null) {
            // Negative cache curto: numa indisponibilidade do Google, os retries
            // do utilizador respondem da cache em vez de voltar a esperar pelo Google.
            Cache::put($cacheKey, [], self::FAILURE_CACHE_TTL_SECONDS);

            return [];
        }

        Cache::put($cacheKey, $predictions, now()->addHours(self::CACHE_TTL_HOURS));

        return $predictions;
    }

    private function fetchAutocompleteFromGoogle(string $input): ?array
    {
        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get(self::AUTOCOMPLETE_URL, [
                    'key' => config('geocoder.key'),
                    'input' => $input,
                    'language' => 'pt',
                    'components' => 'country:pt',
                    'types' => 'address',
                ]);

            $status = $response->json('status');

            if (! $response->successful() || ! in_array($status, ['OK', 'ZERO_RESULTS'], true)) {
                Log::error('Google Places Autocomplete error', [
                    'status' => $status,
                    'http_status' => $response->status(),
                    'error' => $response->json('error_message'),
                ]);

                return null;
            }

            $predictions = collect($response->json('predictions') ?? []);

            $detailsByPlaceId = $this->fetchDetailsForPlaceIds(
                $predictions->pluck('place_id')->filter()->values()->all()
            );

            return $predictions->map(function (array $prediction) use ($detailsByPlaceId) {
                $details = $detailsByPlaceId[$prediction['place_id'] ?? ''] ?? null;

                return [
                    'place_id' => $prediction['place_id'] ?? null,
                    'description' => $prediction['description'] ?? null,
                    'street_name' => $details['street_name'] ?? null,
                    'street_number' => $details['street_number'] ?? null,
                    'city' => $details['city'] ?? null,
                    'postal_code' => $details['postal_code'] ?? null,
                    'state' => $details['state'] ?? null,
                    'latitude' => $details['latitude'] ?? null,
                    'longitude' => $details['longitude'] ?? null,
                ];
            })->toArray();
        } catch (Throwable $e) {
            Log::error('Google Places Autocomplete error', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Busca os detalhes em paralelo (só para os place_ids fora de cache).
     *
     * @param  string[]  $placeIds
     * @return array<string, ?array> detalhes por place_id (null quando a chamada falhou)
     */
    private function fetchDetailsForPlaceIds(array $placeIds): array
    {
        $details = [];
        $misses = [];

        foreach ($placeIds as $placeId) {
            $cached = Cache::get('places_details:'.$placeId);
            if ($cached !== null) {
                $details[$placeId] = $cached;
            } else {
                $misses[] = $placeId;
            }
        }

        if ($misses === []) {
            return $details;
        }

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $placeId) => $pool->as($placeId)
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get(self::DETAILS_URL, [
                    'key' => config('geocoder.key'),
                    'place_id' => $placeId,
                    'language' => 'pt',
                    'fields' => 'address_components,geometry',
                ]),
            $misses,
        ));

        foreach ($misses as $placeId) {
            $response = $responses[$placeId] ?? null;

            // Entradas falhadas do pool vêm como exceções, não Response.
            if (! $response instanceof Response
                || ! $response->successful()
                || $response->json('status') !== 'OK') {
                // Falha num detalhe não invalida a sugestão (a app tem fallback local
                // via parse da description); não cachear para tentar de novo depois.
                $details[$placeId] = null;

                continue;
            }

            $components = $response->json('result.address_components') ?? [];
            $location = $response->json('result.geometry.location');

            $parsed = [
                ...$this->parseAddressComponents($components),
                'latitude' => $location ? (float) $location['lat'] : null,
                'longitude' => $location ? (float) $location['lng'] : null,
            ];

            Cache::put('places_details:'.$placeId, $parsed, now()->addDays(self::DETAILS_CACHE_TTL_DAYS));

            $details[$placeId] = $parsed;
        }

        return $details;
    }

    /**
     * @param  array<int, array{types: string[], long_name: string}>  $components
     * @return array{street_name: ?string, street_number: ?string, city: ?string, postal_code: ?string, state: ?string}
     */
    private function parseAddressComponents(array $components): array
    {
        return [
            'street_name' => collect($components)->firstWhere('types.0', 'route')['long_name'] ?? null,
            'street_number' => collect($components)->firstWhere('types.0', 'street_number')['long_name'] ?? null,
            'city' => collect($components)->firstWhere('types.0', 'administrative_area_level_2')['long_name'] ?? null,
            'postal_code' => collect($components)->firstWhere('types.0', 'postal_code')['long_name'] ?? null,
            'state' => collect($components)->firstWhere('types.0', 'administrative_area_level_1')['long_name'] ?? null,
        ];
    }
}
