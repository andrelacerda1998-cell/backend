<?php

namespace App\Http\Controllers\Api\Common;

use App\Exceptions\Api\User\CantDeleteAccountWithActiveServices;
use App\Exceptions\Api\User\CantDeleteAccountWithBalance;
use App\Exceptions\Api\User\WrongCredentials;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Common\DeleteAccountRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DeleteAccountController extends Controller
{
    public function __invoke(DeleteAccountRequest $request)
    {
        try {
            $user = auth('api')->user();

            if (Hash::check($request->get('password'), $user->password)) {

                if ($user->balance > 0) {
                    throw new CantDeleteAccountWithBalance();
                }

                // Não permitir eliminar um vendor com serviços ativos (ACCEPTED/FINISHED/ARRIVED),
                // que ficariam órfãos. Erro limpo em vez de eliminação parcial.
                if ($user->isVendor() && $user->vendor?->openServices()->exists()) {
                    throw new CantDeleteAccountWithActiveServices();
                }

                DB::transaction(function () use ($user) {
                    $user->delete();
                    if ($user->isVendor()) {
                        $user->vendor->delete();
                    }
                });

                return ApiSuccessResponse::make();
            }else{
                throw new WrongCredentials();
            }
        }catch (\Exception $e) {
            return new ApiErrorResponse($e);
        }

    }
}
