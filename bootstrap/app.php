<?php

use App\Http\Middleware\PassportCookie;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $m): void {
        if ($proxies = env('TRUSTED_PROXIES')) {
            $m->trustProxies(at: $proxies);
        }
        $m->alias(['passport.cookie' => PassportCookie::class]);
        $m->prependToPriorityList(AuthenticatesRequests::class, PassportCookie::class);
        $m->redirectGuestsTo(fn (Request $r) => null);
    })
    ->withExceptions(function (Exceptions $e): void {
        $e->shouldRenderJsonWhen(fn (Request $r, Throwable $t) => $r->is('api/*') || $r->expectsJson());
    })
    ->create();
