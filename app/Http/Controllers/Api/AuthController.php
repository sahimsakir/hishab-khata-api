<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\Rules\Password;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    private function signedIn(Request $r, $user): JsonResponse
    {
        $r->session()->regenerate();

        return response()->json(['user' => $user])->withCookie($this->tokenCookie($this->auth->issue($user)));
    }

    private function tokenCookie(string $token)
    {
        return Cookie::make(config('money.auth_cookie'), $token, config('money.token_minutes'), '/', config('session.domain'), (bool) config('session.secure'), true, false, 'lax');
    }

    public function csrf(Request $r): JsonResponse
    {
        return response()->json(['csrf_token' => $r->session()->token()]);
    }

    public function register(Request $r): JsonResponse
    {
        $data = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:254', 'password' => ['required', 'confirmed', Password::min(8)], 'password_confirmation' => 'required']);

        return $this->signedIn($r, $this->auth->register($data));
    }

    public function login(Request $r): JsonResponse
    {
        return $this->signedIn($r, $this->auth->login($r->validate(['email' => 'required|email', 'password' => 'required|string'])));
    }

    public function me(Request $r): JsonResponse
    {
        return response()->json(['user' => $r->user('api')]);
    }

    public function logout(Request $r): JsonResponse
    {
        $this->auth->revoke($r->user('api'));
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return response()->json(['message' => 'Signed out'])->withCookie(Cookie::forget(config('money.auth_cookie'), '/', config('session.domain')));
    }

    public function googleRedirect(Request $r)
    {
        $r->session()->forget('google_link_user');

        return Socialite::driver('google')->scopes(['openid', 'profile', 'email'])->redirect();
    }

    public function googleLink(Request $r)
    {
        $r->session()->put('google_link_user', $r->user('api')->id);

        return Socialite::driver('google')->scopes(['openid', 'profile', 'email'])->redirect();
    }

    public function googleCallback(Request $r)
    {
        try {
            // Stateful Socialite validates the single-use OAuth state in this session.
            $google = Socialite::driver('google')->user();
            $link = $r->session()->pull('google_link_user');
            $user = $this->auth->google($google, $link ? (int) $link : null);
            $r->session()->regenerate();

            return redirect(config('money.frontend_url').'/auth/callback')->withCookie($this->tokenCookie($this->auth->issue($user)));
        } catch (Throwable $e) {
            $r->session()->forget('google_link_user');

            // Detailed provider errors/tokens are never reflected into URLs or logs.
            return redirect(config('money.frontend_url').'/login?google_error=1');
        }
    }
}
