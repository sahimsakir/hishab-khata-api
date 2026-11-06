<?php

namespace App\Repositories;

use App\Contracts\UserRepository;
use App\Models\User;

class EloquentUserRepository implements UserRepository
{
    public function byEmail(string $email): ?User
    {
        return User::where('email', strtolower($email))->first();
    }

    public function byGoogle(string $subject): ?User
    {
        return User::where('google_id', $subject)->first();
    }

    public function create(array $attributes): User
    {
        return User::create($attributes);
    }

    public function update(User $user, array $attributes): User
    {
        $user->fill($attributes)->save();

        return $user->fresh();
    }

    public function lock(int $userId): User
    {
        return User::whereKey($userId)->lockForUpdate()->firstOrFail();
    }
}
