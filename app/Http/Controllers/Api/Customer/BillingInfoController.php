<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\UpdateBillingInfoRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use Illuminate\Http\Request;

class BillingInfoController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = auth('api')->user();
            if (! $user) {
                return ApiSuccessResponse::make(['billingInfo' => null]);
            }

            $billingInfo = $user->billingInfo?->makeHidden(['id', 'created_at', 'updated_at', 'user_id']);

            return ApiSuccessResponse::make(compact('billingInfo'));
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }
    }

    public function store(UpdateBillingInfoRequest $request)
    {
        try {
            $user = auth('api')->user();
            $user->billingInfo()->updateOrCreate([], $request->validated());

            return ApiSuccessResponse::make();
        } catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }

    }
}
