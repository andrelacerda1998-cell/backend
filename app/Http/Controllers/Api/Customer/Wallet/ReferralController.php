<?php

namespace App\Http\Controllers\Api\Customer\Wallet;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Services\Carteira\ConviteRecusado;
use App\Services\Carteira\Convites;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Convida um amigo", dentro da Carteira.
 *
 * GET  /v1/customer/referral        — o código e os contadores
 * POST /v1/customer/referral/apply  — o amigo põe um código
 */
class ReferralController extends Controller
{
    public function show(Convites $convites): ApiSuccessResponse
    {
        return ApiSuccessResponse::make($convites->resumo(auth('api')->user()));
    }

    public function apply(Request $request, Convites $convites): ApiSuccessResponse|ApiErrorResponse
    {
        $request->validate(['code' => 'required|string|max:20']);

        try {
            $convite = $convites->aplicar(auth('api')->user(), $request->string('code')->value());
        } catch (ConviteRecusado $e) {
            return new ApiErrorResponse(
                ValidationException::withMessages(['code' => $e->getMessage()]),
                $e->getMessage(),
                422,
            );
        }

        return ApiSuccessResponse::make([
            'referral_id' => $convite->id,
            'credit' => Convites::VALOR,
            'message' => self::mensagemAplicado(),
        ]);
    }

    public static function mensagemAplicado(): string
    {
        return __('convites.aplicado', [
            'valor' => number_format(Convites::VALOR / 100, 0),
            'minimo' => number_format(Convites::MINIMO_DO_SERVICO / 100, 0),
        ]);
    }
}
