<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\DTO\Services\AddressCoordinatesDTO;
use App\Enums\Services\AddressType;
use App\Exceptions\Api\Customer\CustomerCantRequestServices;
use App\Exceptions\Api\Customer\CustomerDontHaveMainAddress;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\Services\RequestServiceRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Vendor;
use App\Services\Customer\Services\ScheduleVendorSearchService;
use App\Services\Customer\Services\VendorSearchService;
use App\Services\RateService;
use App\Trait\Services\HasVendorDistance;

class RequestServiceController extends Controller
{
    use HasVendorDistance;

    public function __invoke(RequestServiceRequest $request, VendorSearchService $searchService)
    {
        try {
            $currentUser = auth()->user();
            $mainAddress = $currentUser->mainAddress();

            if (! $currentUser->canRequestService()) {
                throw new CustomerCantRequestServices;
            } elseif (! $mainAddress) {
                throw new CustomerDontHaveMainAddress;
            }

            $userAddress = AddressCoordinatesDTO::fromAddress($mainAddress);

            $requestedServiceType = ServicesType::find($request->get('service_type'));

            $matchingVendors = $searchService->search($userAddress, $requestedServiceType, false);

            $transformedVendors = $this->transformVendors($matchingVendors, $requestedServiceType, $userAddress);
            $transformedVendors = $transformedVendors->filter();

            return new ApiSuccessResponse(['vendors' => $transformedVendors]);
        } catch (\Exception $exception) {
            return new ApiErrorResponse($exception);
        }
    }

    public function guestSearch(\Illuminate\Http\Request $request, VendorSearchService $searchService, ScheduleVendorSearchService $scheduleSearchService)
    {
        // Validação fora do try: a ValidationException tem de propagar para o handler
        // global (422), senão o catch abaixo mascara-a num 500 "Something went wrong".
        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $latitude = (float) $request->get('latitude');
        $longitude = (float) $request->get('longitude');

        // Rejeitar Null Island (0/0): coords válidas no cast mas lixo no negócio.
        if ($latitude === 0.0 && $longitude === 0.0) {
            return new ApiErrorResponse(null, 'Invalid location coordinates.', 422);
        }

        try {
            $serviceTypeId = $request->get('service_type_id');
            $isScheduled = $request->boolean('scheduled');

            $serviceType = ServicesType::find($serviceTypeId);

            if (! $serviceType) {
                return new ApiSuccessResponse(['vendors' => []]);
            }

            $guestAddress = new AddressCoordinatesDTO($latitude, $longitude);

            if ($isScheduled) {
                $matchingVendors = $scheduleSearchService->search($guestAddress, $serviceType, false);

                $transformed = $matchingVendors->transform(function (Vendor $vendor) use ($serviceType, $guestAddress) {
                    $rateService = app(RateService::class);
                    $hourlyRate = $vendor->getRawOriginal('price_rate');
                    $timeService = $serviceType->time;

                    $scheduleAddress = $vendor->addresses()
                        ->where('address_type', AddressType::SCHEDULE_ADDRESS)
                        ->first();

                    if ($scheduleAddress === null) {
                        return null;
                    }

                    $distance = $this->calculateVendorDistance($vendor, $guestAddress);
                    $price = $rateService->calculateForCustomerForSchedule($hourlyRate, $timeService, $distance);
                    $original_price = $rateService->calculateForCustomerForOldPrice($hourlyRate, $timeService, $distance);

                    return [
                        'id' => $vendor->id,
                        'name' => $vendor->user->name,
                        'rate' => $price,
                        'original_price' => $original_price,
                        'distance' => $distance,
                        'rating' => $vendor->averageRating()->where('operation_area_id', $serviceType->operation_area_id)->first()->average_rating ?? 5,
                        'avatar' => $vendor->user->avatar,
                    ];
                })->filter()->values()->take(3);
            } else {
                $matchingVendors = $searchService->search($guestAddress, $serviceType, false);

                $transformed = $matchingVendors->transform(function (Vendor $vendor) use ($serviceType, $guestAddress) {
                    $rateService = app(RateService::class);
                    $hourlyRate = $vendor->getRawOriginal('price_rate');
                    $timeService = $serviceType->time;

                    if ($vendor->currentLocation == null) {
                        return null;
                    }

                    $distance = $this->calculateVendorDistanceInstantService($vendor, $guestAddress);
                    $price = $rateService->calculateForCustomerInstantService($hourlyRate, $timeService, $distance);

                    return [
                        'id' => $vendor->id,
                        'name' => $vendor->user->name,
                        'rate' => $price,
                        'distance' => $distance,
                        'rating' => $vendor->averageRating()->where('operation_area_id', $serviceType->operation_area_id)->first()->average_rating ?? 5,
                        'avatar' => $vendor->user->avatar,
                    ];
                })->filter()->values()->take(3);
            }

            return new ApiSuccessResponse(['vendors' => $transformed]);
        } catch (\Exception $exception) {
            return new ApiErrorResponse($exception);
        }
    }

    private function transformVendors($vendors, ServicesType $serviceType, $userAddress)
    {
        return $vendors->transform(function (Vendor $vendor) use ($serviceType, $userAddress) {
            $rateService = app(RateService::class);

            $vendorUser = $vendor->user;

            $hourlyRate = $vendor->getRawOriginal('price_rate');
            $timeService = $serviceType->time;

            if ($vendor->currentLocation == null) {
                return null;
            }
            $distance = $this->calculateVendorDistanceInstantService($vendor, $userAddress);

            $price = $rateService->calculateForCustomerInstantService($hourlyRate, $timeService, $distance);

            return [
                'id' => $vendor->id,
                'name' => $vendorUser->name,
                // 'nif' => $vendorUser->nif,
                'rate' => $price,
                // 'hourly_rate' => $hourlyRate,
                'distance' => $distance,
                'rating' => $vendor->averageRating()->where('operation_area_id', $serviceType->operation_area_id)->first()->average_rating ?? 5,
                'avatar' => $vendorUser->avatar,
            ];
        })->values()->take(3);
    }

    private function calculateRate($hourRate, $distance) {}
}
