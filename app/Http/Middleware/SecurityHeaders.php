<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endurece as respostas do backoffice contra clickjacking e MIME-sniffing.
 *
 * Registado APENAS no painel Filament (BackofficePanelProvider), nunca no grupo `web`
 * inteiro: as rotas de callback 3DS (/payshop/success|failure) vivem no grupo web e fazem
 * redirect()->away() para o deep link do app — aplicar frame-ancestors/X-Frame-Options a
 * essas rotas arriscaria o fluxo de pagamento. Só se usa frame-ancestors 'none' (o Filament
 * é SPA e não é embutido em iframes), sem default-src/script-src restritivo que o partiria.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");

        return $response;
    }
}
