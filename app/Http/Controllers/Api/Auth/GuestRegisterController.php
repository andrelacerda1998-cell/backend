<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\Api\User\WrongApp;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\GuestRegisterRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\Auth\LoginApiResponse;
use App\Models\User;
use App\Services\Common\PhoneLoginSmsService;
use Exception;
use Illuminate\Support\Facades\Cache;
use Spatie\Geocoder\Facades\Geocoder;

class GuestRegisterController extends Controller
{
    public function __invoke(GuestRegisterRequest $request, PhoneLoginSmsService $phoneService)
    {
        try {
            $data = $request->all();
            $phoneNumber = PhoneLoginSmsService::normalizePhoneNumber($data['phone_number']);

            $cacheKey = "guest_phone_verified:{$phoneNumber}";
            $cachedToken = Cache::get($cacheKey);

            if (! $cachedToken || $cachedToken !== $data['verification_token']) {
                return response()->json(['message' => 'Phone number not verified.'], 422);
            }

            Cache::forget($cacheKey);

            // Lock por número: dois registers simultâneos não podem criar contas duplicadas.
            $lock = Cache::lock("guest_register:{$phoneNumber}", 10);

            try {
                $lock->block(5);

                // Cobre formatos legados (+351-X…, X…) e contas soft-deleted.
                $existingUser = $phoneService->findUserByPhone($phoneNumber, withTrashed: true);

                if ($existingUser) {
                    if ($existingUser->trashed()) {
                        $existingUser->restore();
                    }

                    if (! $existingUser->isCustomer()) {
                        throw new WrongApp;
                    }

                    // A morada digitada no checkout passa a ser sempre a morada principal.
                    $this->storeMainAddress($existingUser, $data['address']);

                    $token = auth('api')->login($existingUser);

                    return new LoginApiResponse($token, ['message' => 'Login successful', 'is_existing_user' => true]);
                }

                $user = User::create([
                    'phone_number' => $phoneNumber,
                    'phone_number_verified_at' => now(),
                    'email' => null,
                    'password' => null,
                    'language' => normalizeAcceptLanguage($request->header('Accept-Language')),
                ]);

                $this->storeMainAddress($user, $data['address']);

                $token = auth('api')->login($user);

                return new LoginApiResponse($token, ['message' => 'Account created successfully', 'is_existing_user' => false]);
            } finally {
                $lock->release();
            }
        } catch (Exception $exception) {
            return new ApiErrorResponse($exception);
        }
    }

    private function storeMainAddress(User $user, array $addressData): void
    {
        $municipality = $this->resolveMunicipalityCity($addressData);

        $user->addresses()->update(['main_address' => false]);

        $user->addresses()->create([
            'address_type' => 'house_address',
            'main_address' => true,
            'latitude' => $addressData['latitude'],
            'longitude' => $addressData['longitude'],
            'street_name' => $addressData['street_name'] ?? null,
            'street_number' => $addressData['street_number'] ?? null,
            'additional_info' => $addressData['additional_info'] ?? null,
            'municipality' => $municipality ?? null,
            'postal_code' => $addressData['postal_code'] ?? null,
            'city' => $addressData['city'] ?? null,
            'state' => $addressData['state'] ?? null,
            'country' => $addressData['country'] ?? 'Portugal',
        ]);
    }

    private function resolveMunicipalityCity(array $addressData): ?string
    {
        $lat = $addressData['latitude'] ?? null;
        $lng = $addressData['longitude'] ?? null;

        if ($lat && $lng) {
            try {
                $geo = Geocoder::setLanguage('pt')->getAddressForCoordinates((float) $lat, (float) $lng);
                $municipality = collect($geo['address_components'] ?? [])
                    ->firstWhere('types.0', 'administrative_area_level_2')
                    ?->long_name;

                if ($municipality) {
                    return $municipality;
                }
            } catch (Exception $e) {
                // fall through
            }
        }

        return $addressData['city'] ?? null;
    }
}
