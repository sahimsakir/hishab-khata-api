<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class GoogleConnectionStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_linked_account_returns_connection_status_without_exposing_provider_identifier(): void
    {
        config(['services.google.client_id'=>'test-client','services.google.client_secret'=>'test-secret']);
        $user=User::factory()->create(['google_id'=>'private-provider-id']);
        Passport::actingAs($user);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.google_connected',true)->assertJsonPath('user.google_email',$user->email)->assertJsonPath('user.google_login_available',true)->assertJsonMissingPath('user.google_id')->assertJsonMissingPath('user.password');
        $this->get('/api/auth/google/link')->assertRedirect(config('money.frontend_url').'/profile?google=connected');
    }

    public function test_unlinked_account_returns_false_and_no_linked_email(): void
    {
        config(['services.google.client_id'=>null,'services.google.client_secret'=>null]);
        Passport::actingAs(User::factory()->create());
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.google_connected',false)->assertJsonPath('user.google_email',null)->assertJsonPath('user.google_login_available',false);
    }

    public function test_cancelled_link_returns_to_profile_without_exposing_provider_error(): void
    {
        $this->withSession(['google_link_user'=>User::factory()->create()->id])
            ->get('/api/auth/google/callback?error=access_denied')
            ->assertRedirect(config('money.frontend_url').'/profile?google_error=1');
    }
}
