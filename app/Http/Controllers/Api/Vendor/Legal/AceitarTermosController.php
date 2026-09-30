<?php

namespace App\Http\Controllers\Api\Vendor\Legal;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiErrorResponse;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\TermsAcceptance;
use Illuminate\Http\Request;

/**
 * O técnico aceita, expressamente, a versão em vigor dos Termos.
 *
 * Porque é um passo próprio e não um `accepted_terms = true` no registo: a
 * cláusula da perda do saldo retira dinheiro ganho, e para ser oponível tem de
 * ter sido mostrada e aceite em concreto. Um "li e aceito" genérico enterrado
 * num formulário não prova que alguém viu aquela cláusula.
 */
class AceitarTermosController extends Controller
{
    /** O que a app precisa de saber para decidir se pergunta. */
    public function estado(Request $request): ApiSuccessResponse
    {
        $vendor = $request->user()->vendor;

        return ApiSuccessResponse::make([
            'document' => TermsAcceptance::DOCUMENTO_PRESTADORES,
            'version_required' => config('legal.provider_terms.version'),
            'version_accepted' => $vendor?->versaoDosTermosAceite(),
            'acceptance_required' => $vendor !== null && ! $vendor->aceitou_os_termos_em_vigor,
            'url' => config('legal.provider_terms.url'),
        ]);
    }

    public function aceitar(Request $request): ApiSuccessResponse|ApiErrorResponse
    {
        $dados = $request->validate([
            // A versão vai no pedido de propósito: a app diz o que MOSTROU, e o
            // servidor recusa se entretanto mudou. Sem isto, uma app antiga
            // aceitava a versão nova sem ninguém a ter lido.
            'version' => ['required', 'string', 'max:32'],
            'content_digest' => ['nullable', 'string', 'size:64'],
        ]);

        $emVigor = config('legal.provider_terms.version');

        if ($dados['version'] !== $emVigor) {
            return new ApiErrorResponse(
                null,
                "Os Termos mudaram entretanto (versão em vigor: {$emVigor}). Volta a abrir para leres a versão atual.",
                409
            );
        }

        $user = $request->user();

        if ($user->vendor === null) {
            return new ApiErrorResponse(null, 'Só os prestadores aceitam estes Termos.', 403);
        }

        /*
         * `firstOrCreate` e não `create`: dois toques no botão, ou um pedido
         * repetido pela rede, não são dois factos. A chave única na tabela
         * garante-o mesmo que dois pedidos cheguem ao mesmo tempo.
         *
         * A linha nunca é actualizada -- a data que fica é a da PRIMEIRA
         * aceitação, que é a que interessa provar.
         */
        $aceitacao = TermsAcceptance::firstOrCreate(
            [
                'user_id' => $user->id,
                'document' => TermsAcceptance::DOCUMENTO_PRESTADORES,
                'version' => $emVigor,
            ],
            [
                'accepted_at' => now(),
                'content_digest' => $dados['content_digest'] ?? null,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ]
        );

        return ApiSuccessResponse::make([
            'version' => $aceitacao->version,
            'accepted_at' => $aceitacao->accepted_at?->toIso8601String(),
        ]);
    }
}
