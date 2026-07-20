<?php

namespace App\Http\Controllers\Api\Customer\Schedule;

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
use App\Services\RateService;

class SearchScheduleVendorsController extends Controller
{
    public function __invoke(RequestServiceRequest $request, ScheduleVendorSearchService $searchService)
    {
        try {
            $currentUser = auth()->user();
            $userAddress = $currentUser->mainAddress();

            if (! $currentUser->canRequestService()) {
                throw new CustomerCantRequestServices;
            } elseif (! $userAddress) {
                throw new CustomerDontHaveMainAddress;
            }

            $requestedServiceType = ServicesType::find($request->get('service_type'));

            $matchingVendors = $searchService->search($userAddress, $requestedServiceType, $currentUser->is_test);

            $transformedVendors = $this->transformVendors(
                $matchingVendors,
                $requestedServiceType,
                $userAddress
            );
            $transformedVendors = $transformedVendors->filter();

            return new ApiSuccessResponse(['vendors' => $transformedVendors->values()]);
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

            // Get schedule address for distance calculation
            $scheduleAddress = $vendor->addresses()
                ->where('address_type', AddressType::SCHEDULE_ADDRESS)
                ->first();

            if ($scheduleAddress === null) {
                return null;
            }

            if (!($vendor->user?->hasVerifiedPhoneNumber() &&
                $vendor->user?->hasVerifiedEmail() &&
                $vendor->all_documents_verified &&
                $vendor->iban != null &&
                $vendor->invoice_workspace != null &&
                str_contains($vendor->at_user ?? '', '/'))){
                return null;
            }

            $distance = $this->calculateDistance($scheduleAddress, $userAddress);
            $price = $rateService->calculateForCustomerForSchedule($hourlyRate, $timeService, $distance);
            $original_price = $rateService->calculateForCustomerForOldPrice($hourlyRate, $timeService, $distance);

            // Check if vendor has auto-accept on any day
            $hasAutoAccept = $vendor->scheduleAvailable()
                ->where('is_enabled', true)
                ->where('auto_accept', true)
                ->exists();

            return [
                'id' => $vendor->id,
                'name' => $vendorUser->name,
                'rate' => $price,
                'original_price' => $original_price,
                'distance' => $distance,
                'rating' => $vendor->averageRating()
                    ->where('operation_area_id', $serviceType->operation_area_id)
                    ->first()
                    ->average_rating ?? 5,
                'avatar' => $vendorUser->avatar,
                'is_online' => $vendor->status->value === 'Online',
                'has_auto_accept' => $hasAutoAccept,
            ];
        })->take(3);
    }

    private function calculateDistance($vendorAddress, $customerAddress): float
    {
        return calculate_distance(
            $vendorAddress->latitude,
            $vendorAddress->longitude,
            $customerAddress->latitude,
            $customerAddress->longitude
        );
    }
}
