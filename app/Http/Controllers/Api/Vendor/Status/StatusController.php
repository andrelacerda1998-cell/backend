<?php

namespace App\Http\Controllers\Api\Vendor\Status;

use App\Enums\Services\ServiceStatus;
use App\Enums\Vendors\StatusVendor;
use App\Exceptions\Api\Vendor\Status\VendorAlreadyHasDeviceConnected;
use App\Exceptions\Api\Vendor\Status\VendorHasServiceOpen;
use App\Exceptions\Api\Vendor\VendorAccountNotValidated;
use App\Exceptions\Api\Vendor\VendorAccountWorkspaceRequired;
use App\Exceptions\Api\Vendor\VendorATAccountInvalid;
use App\Exceptions\Api\Vendor\VendorCantAcceptServices;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\Status\StatusRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use Request;

class StatusController extends Controller
{
    public function __invoke(StatusRequest $request)
    {
        try {
            $vendor = $request->user()->vendor;
            $status = $request->get('status');
            $currentStatus = $vendor->status;

            if ($currentStatus !== StatusVendor::OFFLINE) {
                $currentLocation = $vendor->currentLocation;
                if ($currentLocation) {
                    if ($currentLocation->updated_at->diffInMinutes(now()) < 10) {
                        if ($vendor->currentLocation->device_id !== $request->get('device_id')) {
                            throw new VendorAlreadyHasDeviceConnected();
                        }
                    }
                }
            }



            if($status === StatusVendor::ONLINE->value){
                if(!$vendor->at_valid){
                    throw new VendorATAccountInvalid();
                }
                if (!$vendor->all_documents_verified){
                    throw new VendorAccountNotValidated();
                }
                if ($vendor->invoice_workspace == null){
                    throw new VendorAccountWorkspaceRequired();
                }
                if (!$vendor->can_accept_service){
                    throw new VendorCantAcceptServices();
                }
            } else {
                $service = $vendor->services()->whereIn('status', [ServiceStatus::FINISHED, ServiceStatus::ACCEPTED])->get()->first();
                if ($service) {
                    throw new VendorHasServiceOpen();
                }
            }
            $vendor->status = $status;
            $vendor->save();


            return new ApiSuccessResponse;
        }catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }

    }

    public function check(Request $request)
    {
        $vendor = auth()->user()->vendor;

        $currentLocation = $vendor->currentLocation;
        $deviceId = null;
        if ($currentLocation) {
            if ($currentLocation->updated_at->diffInMinutes(now()) < 10) {
                $deviceId = $vendor->currentLocation->device_id;
            }
            if ($vendor->status === StatusVendor::OFFLINE) {
                $deviceId = null;
            }
        }


        return new ApiSuccessResponse(['status' => $vendor->status, 'device_id'=>$deviceId]);
    }
}
