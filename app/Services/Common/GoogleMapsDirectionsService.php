<?php

namespace App\Services\Common;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleMapsDirectionsService
{
    private const CACHE_TTL_MINUTES = 60;

    private const COORDINATE_PRECISION = 3;

    public function getRoute(float $originLat, float $originLng, float $destLat, float $destLng): ?array
    {
        $cacheKey = $this->generateCacheKey($originLat, $originLng, $destLat, $destLng);

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_TTL_MINUTES), function () use ($originLat, $originLng, $destLat, $destLng) {
            return $this->fetchRouteFromGoogle($originLat, $originLng, $destLat, $destLng);
        });
    }

    public function getRouteForService(\App\Models\Service $service): ?array
    {
        $vendor = $service->vendor;

        // Use the address stored on the service at creation time, not the customer's
        // current main address — they may differ if the customer updated their address
        // after the service was booked, which would cause the route to go to a
        // different location than the destination pin shown on the map.
        $destLat = $service->address['latitude'] ?? null;
        $destLng = $service->address['longitude'] ?? null;

        if (! $destLat || ! $destLng) {
            return null;
        }

        $vendorLat = $vendor->currentLocation?->latitude;
        $vendorLng = $vendor->currentLocation?->longitude;

        if (! $vendorLat || ! $vendorLng) {
            $vendorAddress = $vendor->addresses()->first();
            $vendorLat = $vendorAddress?->latitude;
            $vendorLng = $vendorAddress?->longitude;
        }

        if (! $vendorLat || ! $vendorLng) {
            return null;
        }

        $route = $this->getRoute($vendorLat, $vendorLng, $destLat, $destLng);

        return [
            'origin' => [
                'latitude' => $vendorLat,
                'longitude' => $vendorLng,
            ],
            'destination' => [
                'latitude' => $destLat,
                'longitude' => $destLng,
            ],
            'route' => $route,
        ];
    }

    private function fetchRouteFromGoogle(float $originLat, float $originLng, float $destLat, float $destLng): ?array
    {
        $apiKey = config('geocoder.key');

        if (! $apiKey) {
            Log::warning('Google Maps API key not configured');

            return null;
        }

        try {
            $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/directions/json', [
                'origin' => "{$originLat},{$originLng}",
                'destination' => "{$destLat},{$destLng}",
                'mode' => 'driving',
                'language' => 'pt-PT',
                'key' => $apiKey,
            ]);

            if (! $response->successful()) {
                Log::error('Google Maps Directions API error', ['response' => $response->body()]);

                return null;
            }

            $data = $response->json();

            if (($data['status'] ?? '') !== 'OK') {
                Log::warning('Google Maps Directions API status not OK', ['status' => $data['status'] ?? 'unknown']);

                return null;
            }

            $route = $data['routes'][0] ?? null;

            if (! $route) {
                return null;
            }

            $leg = $route['legs'][0] ?? null;

            $encodedPolyline = $route['overview_polyline']['points'] ?? null;

            return [
                'polyline' => $encodedPolyline,
                'coordinates' => $encodedPolyline ? $this->decodePolyline($encodedPolyline) : [],
                'distance' => [
                    'text' => $leg['distance']['text'] ?? null,
                    'value' => $leg['distance']['value'] ?? null,
                ],
                'duration' => [
                    'text' => $leg['duration']['text'] ?? null,
                    'value' => $leg['duration']['value'] ?? null,
                ],
                'steps' => collect($leg['steps'] ?? [])->map(fn ($step) => [
                    'instruction' => strip_tags($step['html_instructions'] ?? ''),
                    'distance' => $step['distance']['text'] ?? null,
                    'duration' => $step['duration']['text'] ?? null,
                    'polyline' => $step['polyline']['points'] ?? null,
                ])->toArray(),
            ];
        } catch (\Exception $e) {
            Log::error('Google Maps Directions API exception', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function generateCacheKey(float $originLat, float $originLng, float $destLat, float $destLng): string
    {
        $originLat = round($originLat, self::COORDINATE_PRECISION);
        $originLng = round($originLng, self::COORDINATE_PRECISION);
        $destLat = round($destLat, self::COORDINATE_PRECISION);
        $destLng = round($destLng, self::COORDINATE_PRECISION);

        return "directions:{$originLat},{$originLng}:{$destLat},{$destLng}";
    }

    public function clearRouteCache(float $originLat, float $originLng, float $destLat, float $destLng): void
    {
        $cacheKey = $this->generateCacheKey($originLat, $originLng, $destLat, $destLng);
        Cache::forget($cacheKey);
    }

    /**
     * Decode a Google Maps encoded polyline string into an array of coordinates.
     */
    private function decodePolyline(string $encoded): array
    {
        $points = [];
        $index = 0;
        $len = strlen($encoded);
        $lat = 0;
        $lng = 0;

        while ($index < $len) {
            $shift = 0;
            $result = 0;

            do {
                $b = ord($encoded[$index++]) - 63;
                $result |= ($b & 0x1F) << $shift;
                $shift += 5;
            } while ($b >= 0x20);

            $dlat = (($result & 1) ? ~($result >> 1) : ($result >> 1));
            $lat += $dlat;

            $shift = 0;
            $result = 0;

            do {
                $b = ord($encoded[$index++]) - 63;
                $result |= ($b & 0x1F) << $shift;
                $shift += 5;
            } while ($b >= 0x20);

            $dlng = (($result & 1) ? ~($result >> 1) : ($result >> 1));
            $lng += $dlng;

            $points[] = [
                'latitude' => $lat / 1e5,
                'longitude' => $lng / 1e5,
            ];
        }

        return $points;
    }
}
