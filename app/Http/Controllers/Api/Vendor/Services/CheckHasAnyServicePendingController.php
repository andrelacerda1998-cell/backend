<?php

namespace App\Http\Controllers\Api\Vendor\Services;

use App\DTO\Services\ServiceRequestedData;
use App\Enums\Services\PaymentStatus;
use App\Enums\Services\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\User;
use App\Repository\Service\ServiceRepository;

class CheckHasAnyServicePendingController extends Controller
{
    public function __construct(private readonly ServiceRepository $serviceRepository) {}

    public function service(): ApiSuccessResponse
    {
        /** @var User $user */
        $user = auth()->user();

        // $service = $user->vendor->services()->whereIn('status', [ServiceStatus::ACCEPTED])->get()->first();

        // return new ApiSuccessResponse(compact('service'));

        $service = $this->serviceRepository->getPendingPaidServices($user)->first();

        if (!$service) {
            return new ApiSuccessResponse(compact('service'));
        }

        $service = ServiceRequestedData::fromArray($service, $user);

        return new ApiSuccessResponse(compact('service'));
    }

    public function services(): ApiSuccessResponse
    {
        /** @var User $user */
        $user = auth()->user();

        $services = $this->serviceRepository->getPendingPaidServices($user);
        $services = $services->map(function ($service) use ($user) {
            return ServiceRequestedData::fromArray($service, $user);
        });

        return new ApiSuccessResponse(compact('services'));
    }
}
