<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $accept_language = $request->header('Accept-Language');

        if ($accept_language) {
            $accept_language = strtolower(explode(',', $accept_language)[0]);

            if (in_array($accept_language, config('app.locales'))) {
                app()->setLocale($accept_language);
            }
        }

        return $next($request);
    }
}
