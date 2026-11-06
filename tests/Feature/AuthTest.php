<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\ClientRepository;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->artisan('passport:keys', ['--force' => true]);
        app(ClientRepository::class)->createPersonalAccessGrantClient('Test browser', 'users');
    }

    public function test_registration_sets_httponly_cookie_without_returning_token(): void
    {
        $r = $this->postJson('/api/auth/register', ['name' => 'Example', 'email' => 'example@example.com', 'password' => 'secret1234', 'password_confirmation' => 'secret1234']);
        $r->assertOk()->assertJsonPath('user.email', 'example@example.com')->assertCookie('hishabkhata_access')->assertJsonMissingPath('access_token');
        $cookies = $r->headers->getCookies();
        $access = collect($cookies)->first(fn ($c) => $c->getName() === 'hishabkhata_access');
        $this->assertTrue($access->isHttpOnly());
        $this->assertSame('lax', $access->getSameSite());
    }

    public function test_password_login_rejects_wrong_password(): void
    {
        User::factory()->create(['email' => 'example@example.com', 'password' => 'password123']);
        $this->postJson('/api/auth/login', ['email' => 'example@example.com', 'password' => 'bad'])->assertUnprocessable();
    }

    private function google(string $id, string $email, bool $verified = true): GoogleUser
    {
        return (new GoogleUser)->map(['id' => $id, 'name' => 'Google user', 'email' => $email])->setRaw(['verified_email' => $verified]);
    }

    public function test_google_can_create_a_new_user(): void
    {
        $user = app(AuthService::class)->google($this->google('sub1', 'new@example.com'));
        $this->assertSame('new@example.com', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->password);
    }

    public function test_google_cannot_silently_take_over_a_password_account(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);
        $this->expectException(ValidationException::class);
        app(AuthService::class)->google($this->google('sub2', 'existing@example.com'));
    }

    public function test_google_link_requires_verified_matching_email(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.com']);
        $linked = app(AuthService::class)->google($this->google('sub3', 'existing@example.com'), $user->id);
        $this->assertSame('sub3', $linked->google_id);
        $this->expectException(ValidationException::class);
        app(AuthService::class)->google($this->google('sub4', 'different@example.com'), $user->id);
    }

    public function test_google_rejects_unverified_email(): void
    {
        $this->expectException(ValidationException::class);
        app(AuthService::class)->google($this->google('sub5', 'new@example.com', false));
    }

    public function test_csrf_is_required_for_browser_login(): void
    {
        // Laravel normally disables CSRF during tests. Force production-style CSRF here.
        $this->app->instance('env', 'local');
        $this->postJson('/api/auth/login', ['email' => 'x@example.com', 'password' => 'bad'])->assertStatus(419);
    }
}
