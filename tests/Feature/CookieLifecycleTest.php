<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

class CookieLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_passport_cookie_authenticates_and_logout_revokes_it(): void
    {
        $this->artisan('passport:keys', ['--force' => true]);
        app(ClientRepository::class)->createPersonalAccessGrantClient('Browser', 'users');
        $r = $this->postJson('/api/auth/register', ['name' => 'Cookie user', 'email' => 'cookie@example.com', 'password' => 'secret1234', 'password_confirmation' => 'secret1234'])->assertOk();
        $cookie = $r->getCookie('hishabkhata_access', false)->getValue();
        $this->app['auth']->forgetGuards();
        $this->withCredentials()->withUnencryptedCookie('hishabkhata_access', $cookie)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.email', 'cookie@example.com');
        $this->app['auth']->forgetGuards();
        $this->withCredentials()->withUnencryptedCookie('hishabkhata_access', $cookie)->postJson('/api/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withCredentials()->withUnencryptedCookie('hishabkhata_access', $cookie)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_injected_authorization_header_is_not_used_without_cookie(): void
    {
        $this->getJson('/api/auth/me', ['Authorization' => 'Bearer client-supplied-token'])->assertUnauthorized();
    }
}
