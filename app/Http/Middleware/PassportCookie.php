<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PassportCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        // This SPA accepts Passport credentials ONLY from its encrypted HttpOnly cookie.
        // Never expose a bearer token to React or allow a JS-supplied token to override it.
        $request->headers->remove('Authorization');
        if ($token = $request->cookie(config('money.auth_cookie'))) {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }

        return $next($request);
    }
}
