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

    /**
     * POST /v1/common/referral/check — sem sessão. Diz se o código serve, sem
     * o aplicar: quem pede sem conta escreve-o no checkout, e ele só se aplica
     * quando a conta é criada ao confirmar o telemóvel (guest/register).
     */
    public function check(Request $request, Convites $convites): ApiSuccessResponse|ApiErrorResponse
    {
        $request->validate([
            'code' => 'required|string|max:20',
            'phone_number' => 'nullable|string|max:30',
        ]);

        $telefone = $request->filled('phone_number')
            ? \App\Services\Common\PhoneLoginSmsService::normalizePhoneNumber($request->string('phone_number')->value())
            : null;

        try {
            $codigo = $convites->verificarSemConta($request->string('code')->value(), $telefone);
        } catch (ConviteRecusado $e) {
            // 404 = não é um código de convite (pode ser um cupão: a app segue
            // pelo caminho dos cupões). 422 = é um convite, mas não serve.
            return new ApiErrorResponse(
                ValidationException::withMessages(['code' => $e->getMessage()]),
                $e->getMessage(),
                $e->razao === 'nao_existe' ? 404 : 422,
            );
        }

        return ApiSuccessResponse::make([
            'code' => $codigo->code,
            'credit' => Convites::VALOR,
            'message' => __('convites.pendente', [
                'valor' => number_format(Convites::VALOR / 100, 0),
            ]),
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
