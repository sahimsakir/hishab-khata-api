<?php

namespace App\Contracts;

use App\Models\User;

interface UserRepository
{
    public function byEmail(string $email): ?User;

    public function byGoogle(string $subject): ?User;

    public function create(array $attributes): User;

    public function update(User $user, array $attributes): User;

    public function lock(int $userId): User;
}
