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

            // O NIF do técnico vive dentro do subutilizador AT (formato
            // NIF/subutilizador, garantido pelo regex do UpdateAtUserRequest).
            // Passa a ser derivado daqui em vez de escrito à mão no perfil:
            // antes o mesmo número existia em dois sítios e nada garantia que
            // coincidiam — as faturas podiam sair com NIFs diferentes conforme
            // o caminho (SystemInvoiceService lê users.nif, o InvoiceVendorService
            // já fazia explode do at_user).
            $nifFromAtUser = explode('/', $data['at_user'])[0];
            if ($user->nif !== $nifFromAtUser) {
                $user->nif = $nifFromAtUser;
                $user->save();
            }

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
