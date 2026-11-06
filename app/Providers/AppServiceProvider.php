<?php

namespace App\Providers;

use App\Contracts\FinanceRepository;
use App\Contracts\UserRepository;
use App\Repositories\EloquentFinanceRepository;
use App\Repositories\EloquentUserRepository;
use Carbon\CarbonInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Passport::ignoreRoutes();
        $this->app->bind(FinanceRepository::class, EloquentFinanceRepository::class);
        $this->app->bind(UserRepository::class, EloquentUserRepository::class);
    }

    public function boot(): void
    {
        Passport::personalAccessTokensExpireIn(CarbonInterval::minutes(config('money.token_minutes')));
        // The browser auth flow issues Passport personal access tokens on the server.
        // Disable unused public OAuth endpoints; the SPA never handles raw tokens.
        Passport::ignoreRoutes();
        RateLimiter::for('signin', fn (Request $r) => [Limit::perMinute(10)->by($r->ip()), Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip())]);
        RateLimiter::for('money', fn (Request $r) => Limit::perMinute(180)->by($r->user('api')?->id ?: $r->ip()));
    }
}
