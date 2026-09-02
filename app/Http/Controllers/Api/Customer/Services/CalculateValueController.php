<?php

namespace App\Http\Controllers\Api\Customer\Services;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Customer\Services\CalculateValueRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Vendor;
use App\Models\Voucher;
use App\Trait\Services\CalculateServicePriceForCustomer;
use Exception;
use Illuminate\Http\Request;

class CalculateValueController extends Controller
{
    use CalculateServicePriceForCustomer;

    public function __invoke(CalculateValueRequest $request)
    {
        try {

            $isScheduled = $request->boolean('scheduled');
            $isGuest = $request->boolean('is_guest');
            $address = $request->get('address');
            $vendor = $this->findVendor($request->get('vendor_id'), $isScheduled);

            $serviceType = ServicesType::findOrFail($request->get('service_type'));

            $voucher = null;
            if ($request->has('voucher_id') && $request->get('voucher_id')) {
                $voucher = Voucher::find($request->get('voucher_id'));
            }

            $transaction = $this->calculateTransaction($vendor, $serviceType, $isScheduled, $voucher, $isGuest, $address, (int) $request->get('quantity', 1));

            return new ApiSuccessResponse($transaction);

        } catch (Exception $exception) {
            \DB::rollBack();

            return new ApiErrorResponse($exception);
        }

    }

    public function guestCalculate(Request $request)
    {
        try {
            $serviceTypeId = $request->get('service_type_id');
            $vendorId = $request->get('vendor_id');
            $latitude = (float) $request->get('latitude');
            $longitude = (float) $request->get('longitude');

            $serviceType = ServicesType::find($serviceTypeId);
            $vendor = Vendor::find($vendorId);

            if (! $serviceType || ! $vendor) {
                return new ApiSuccessResponse([
                    'amount' => 0,
                    'amount_formated' => '0.00',
                    'travel_amount' => 0,
                    'travel_amount_formated' => '0.00',
                    'original_amount' => 0,
                    'original_amount_formated' => '0.00',
                    'discount_amount' => 0,
                    'discount_amount_formated' => '0.00',
                    'balance' => 0,
                    'balance_formated' => '0.00',
                    'value_for_payment' => 0,
                    'value_for_payment_formated' => '0.00',
                    'balance_after_payment' => 0,
                    'balance_after_payment_formated' => '0.00',
                    'balance_total_used' => 0,
                    'balance_total_used_formated' => '0.00',
                ]);
            }

            $result = $this->calculateGuestPrice($vendor, $serviceType, $latitude, $longitude, (int) $request->get('quantity', 1));

            return new ApiSuccessResponse($result);
        } catch (Exception $exception) {
            return new ApiErrorResponse($exception);
        }
    }
}
