<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Exceptions\Api\Vendor\VendorDuplicatedSequence;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Vendor\UpdateAtUserRequest;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Jobs\Vendor\PrepareWorkspaceJob;
use App\Services\InvoiceXpress\InvoiceVendorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class UpdateAtUserController extends Controller
{
    public function __invoke(UpdateAtUserRequest $request): JsonResponse|ApiSuccessResponse
    {
        DB::beginTransaction();
        try {
            $data = $request->validated();

            $user = auth()->user();
            $vendor = $user->vendor;
            $vendor->at_user = $data['at_user'];
            $vendor->at_password = $data['at_password'];
            $vendor->save();

            DB::commit();

            if ($vendor->auth_token && $vendor->invoice_workspace) {
                $service = new InvoiceVendorService($vendor);
                $service->updateFiscalDetails();
                try {
                    $service->createSequence();
                } catch (VendorDuplicatedSequence) {
                    // ignored
                }

                $vendor->at_valid = true;
                $vendor->at_validated_at = now();
                $vendor->save();
                // PrepareWorkspaceJob::dispatch($vendor);
            }

            return ApiSuccessResponse::make();
        } catch (\Exception $e) {
            DB::rollBack();
            $message = __($e->getMessage());

            return response()->json([
                'message' => $message,
                'errors' => [
                    'at_password' => [
                        $message,
                    ],
                ],
            ], 422);
        }
    }
}
