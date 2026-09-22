<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\ChangeEmailRequest;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use Throwable;

/**
 * Trocar o email da propria conta.
 *
 * O email so era definido no registo e nao havia forma de o corrigir em lado
 * nenhum. Quem se enganasse a escreve-lo ficava a pedir o link de confirmacao
 * para um endereco que nao e o dele — e, sem email confirmado, nao fica apto e
 * nao recebe um unico pedido. O passo 1 de 6 era um beco sem saida.
 *
 * Trocar o email ANULA a confirmacao e manda uma nova: o que estava confirmado
 * era o endereco antigo, e dar por confirmado um endereco que ninguem provou
 * controlar seria pior do que nao ter a funcionalidade.
 */
class ChangeEmailController extends Controller
{
    public function __invoke(ChangeEmailRequest $request)
    {
        $user = $request->user();
        $novo = $request->validated()['email'];

        try {
            // Pedir o mesmo endereco outra vez e o caso de quem nao recebeu o
            // link. Nao se mexe no estado — so se reenvia, e so se fizer falta.
            if ($user->email === $novo) {
                if ($user->hasVerifiedEmail()) {
                    return new ApiErrorResponse(null, 'exceptions.auth.email_already_verified', 409);
                }

                $user->sendEmailVerificationNotification();

                return new ApiSuccessResponse(['email' => $user->email, 'email_verified' => false]);
            }

            $user->forceFill([
                'email' => $novo,
                'email_verified_at' => null,
            ])->save();

            $user->sendEmailVerificationNotification();

            return new ApiSuccessResponse(['email' => $user->email, 'email_verified' => false]);
        } catch (Throwable $e) {
            return new ApiErrorResponse($e);
        }
    }
}
