<?php

namespace App\Services;

use App\Contracts\UserRepository;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Two\User as GoogleUser;

class AuthService
{
    public function __construct(private UserRepository $users) {}

    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            if ($this->users->byEmail($data['email'])) {
                throw ValidationException::withMessages(['email' => 'This email is already registered.']);
            }

            return $this->users->create(['name' => $data['name'], 'email' => strtolower($data['email']), 'password' => Hash::make($data['password'])]);
        });
    }

    public function login(array $data): User
    {
        $user = $this->users->byEmail($data['email']);
        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }

        return $user;
    }

    public function google(GoogleUser $google, ?int $linkUserId = null): User
    {
        if (empty($google->user['verified_email']) && empty($google->user['email_verified'])) {
            throw ValidationException::withMessages(['google' => 'Google email verification is required.']);
        }

        return DB::transaction(function () use ($google, $linkUserId) {
            $existing = $this->users->byGoogle((string) $google->getId());
            if ($linkUserId) {
                $user = $this->users->lock($linkUserId);
                if ($existing && $existing->id !== $user->id) {
                    throw ValidationException::withMessages(['google' => 'This Google account is linked to another user.']);
                }
                if (strtolower($google->getEmail()) !== $user->email) {
                    throw ValidationException::withMessages(['google' => 'Use the Google account with the same email as your HishabKhata account.']);
                }

                return $this->users->update($user, ['google_id' => (string) $google->getId(), 'email_verified_at' => now()]);
            }
            if ($existing) {
                return $existing;
            }
            if ($this->users->byEmail($google->getEmail())) {
                throw ValidationException::withMessages(['google' => 'Sign in with your password first, then connect Google from your profile.']);
            }

            return $this->users->create(['name' => $google->getName() ?: 'HishabKhata user', 'email' => strtolower($google->getEmail()), 'google_id' => (string) $google->getId(), 'email_verified_at' => now(), 'password' => null]);
        });
    }

    public function issue(User $user): string
    {
        return $user->createToken('HishabKhata browser')->accessToken;
    }

    public function revoke(User $user): void
    {
        $user->token()?->revoke();
    }
}
