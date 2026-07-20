<?php

namespace App\Http\Controllers\Api\Common;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\Service;
use App\Services\Common\GoogleMapsDirectionsService;
use Exception;

class GetServiceRouteController extends Controller
{
    public function __construct(
        private readonly GoogleMapsDirectionsService $directionsService
    ) {}

    public function __invoke(Service $service)
    {
        try {
            $user = auth()->user();

            $isCustomer = $service->customer_id === $user->id;
            $isVendor = $service->vendor_id === $user->vendor?->id;

            if (! $isCustomer && ! $isVendor) {
                throw new Exception('Service not found', 404);
            }

            $routeData = $this->directionsService->getRouteForService($service);

            if (! $routeData) {
                throw new Exception('Could not calculate route', 400);
            }

            return new ApiSuccessResponse($routeData);
        } catch (Exception $e) {
            return new ApiErrorResponse($e);
        }
    }
}
