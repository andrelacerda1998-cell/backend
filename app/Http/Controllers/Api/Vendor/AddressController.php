<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Enums\Services\AddressType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\Address\VerifyRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\User;
use App\Services\Common\AddressService;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Spatie\Geocoder\Facades\Geocoder;
use Illuminate\Contracts\Support\Responsable;
use App\Http\Requests\Api\Customer\Address\UpdateRequest;

class AddressController extends Controller
{
    public function __construct(private readonly AddressService $addressService)
    {
    }

    public function get()
    {
        $vendor = auth()->user()->vendor;

        return ApiSuccessResponse::make($vendor->addresses->first());
    }

    public function update(UpdateRequest $request)
    {
        $data = $request->validated();
        $geoAddress = $this->addressService->getCoordinates($data);

        if (!is_array($geoAddress) || empty($geoAddress['address_components'] ?? null) || !isset($geoAddress['lat'], $geoAddress['lng'])) {
            Log::info('address',$geoAddress);
            return new ApiErrorResponse(new \Exception('Could not geocode address.'), 'Address is invalid', 400);
        }

        $vendor = auth()->user()->vendor;

        try {
            $addressData = $this->addressService->transformAddress([
                'address_name' => 'Fiscal Address',
                ...$data,
            ], $geoAddress);
        } catch (\Exception $e) {
            return new ApiErrorResponse($e, "Address is invalid", 400);
        }

        $vendor->addresses()->updateOrCreate([], [
            ...$addressData,
            'user_id' => $vendor->user->id,
            'address_type' => AddressType::FISCAL_ADDRESS,
        ]);

        return new ApiSuccessResponse(['address' => $addressData]);
    }

    public function verify(VerifyRequest $request): Responsable
    {
        $data = $request->validated();
        $geoAddress = $this->addressService->getCoordinates($data);

        if (isset($geoAddress['address_components'])) {
            foreach ($geoAddress['address_components'] as $addressComponent) {
                if (in_array('country', $addressComponent->types) && $addressComponent->short_name !== 'PT') {
                    return new ApiErrorResponse(
                        new Exception('Only addresses from Portugal are accepted.'),
                        'Address is invalid',
                        400
                    );
                }
            }
        }

        return new APISuccessResponse(['address' => $geoAddress]);
    }
}
