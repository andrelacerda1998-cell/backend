<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleLocale
{
    /**
     * Handle an incoming request.
     *
     * Define SEMPRE o locale: sem cabeçalho (ou com um que não casa) fica o
     * português por omissão, em vez de cair no APP_LOCALE (=en). Ver App\Support\Locale.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale(Locale::normalize($request->header('Accept-Language')));

        return $next($request);
    }
}
