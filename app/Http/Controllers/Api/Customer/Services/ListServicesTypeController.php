<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\ServicesType;

class ListServicesTypeController extends Controller
{
    public function __invoke()
    {
        $services = ServicesType::select(['id', 'name', 'starts_from'])->get();

        return new ApiSuccessResponse(compact('services'));
    }
}
