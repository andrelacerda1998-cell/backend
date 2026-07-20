<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Garante que um pedido a uma rota de vendor pertence mesmo a um utilizador com conta de
 * vendor. Sem isto, um token de cliente que atinja `/vendor/*` chega a controladores que
 * fazem `auth()->user()->vendor->id` sobre um `->vendor` nulo → Error → 500 (P-06).
 *
 * Só exige o vendor quando HÁ utilizador autenticado: as rotas do grupo que usam
 * `withoutMiddleware('auth:api')` (ex.: operation-areas público) não têm utilizador e
 * passam sem alteração.
 */
class EnsureUserIsVendor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = auth()->user();

        if ($user && ! $user->vendor) {
            abort(403, 'This action requires a vendor account.');
        }

        return $next($request);
    }
}
