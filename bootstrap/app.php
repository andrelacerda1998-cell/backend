<?php

use App\Exceptions\Api\Vendor\VendorAccountWorkspaceRequired;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Fixar no CIDR do edge/load-balancer via env para evitar spoofing de X-Forwarded-For.
        // Default '*' mantém o comportamento atual até TRUSTED_PROXIES ser definido em produção.
        $trustedProxies = env('TRUSTED_PROXIES', '*');
        $middleware->trustProxies(at: $trustedProxies === '*' ? '*' : explode(',', $trustedProxies));
        $middleware->alias([
            'locale' => App\Http\Middleware\HandleLocale::class,
            'isVendor' => App\Http\Middleware\EnsureUserIsVendor::class,
            'admin.api' => App\Http\Middleware\AdminApiToken::class,
        ]);
        // Aplica o limiter base 'api' (definido em AppServiceProvider) a todas as rotas de
        // API. Fecha o DoS não autenticado (108/119 rotas sem limite). Rotas afiadas têm
        // limites próprios por-rota (throttle:credit-card / :otp / :geocode).
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontReport(Tymon\JWTAuth\Exceptions\TokenExpiredException::class);
        $exceptions->dontReport(App\Exceptions\Api\User\WrongCredentials::class);
        $exceptions->dontReport(VendorAccountWorkspaceRequired::class);
    })->create();
