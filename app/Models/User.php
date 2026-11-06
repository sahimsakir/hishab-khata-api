<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'google_id', 'email_verified_at'];

    protected $hidden = ['password', 'remember_token', 'google_id'];
    protected $appends = ['google_connected', 'google_email', 'google_login_available'];

    public function getGoogleConnectedAttribute(): bool
    {
        return ! empty($this->google_id);
    }

    public function getGoogleEmailAttribute(): ?string
    {
        return $this->google_connected ? $this->email : null;
    }

    public function getGoogleLoginAvailableAttribute(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }
}
