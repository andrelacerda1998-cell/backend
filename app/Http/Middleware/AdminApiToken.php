<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Autenticação servidor-a-servidor para a API de admin (v1/admin/*), consumida
 * pelo backoffice Next.js (piquet-backoffice), nunca pelo browser diretamente.
 *
 * Propositadamente simples (comparação de um token estático, não JWT/Sanctum):
 * quem já garante "és mesmo staff" é o login do backoffice Next.js (Supabase,
 * tabela `staff`, roles ceo/cto) — isto aqui só garante que quem está a chamar
 * é o backend do Next.js e não outra origem qualquer. Se no futuro o backoffice
 * Next.js passar a autenticar-se a sério contra o Laravel, isto pode ser
 * substituído por Sanctum sem mexer nos controllers.
 */
class AdminApiToken
{
    public function handle(Request $request, Closure $next): HttpResponse
    {
        $expected = config('services.admin_api.token');

        if (blank($expected)) {
            // Sem token configurado = API de admin desligada (fail-closed), nunca aberta por omissão.
            return response()->json(['message' => 'Admin API not configured.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $given = $request->bearerToken();

        if (! is_string($given) || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Unauthorized.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
