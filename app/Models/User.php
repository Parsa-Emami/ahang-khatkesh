<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    protected $attributes = ['is_active' => true, 'is_admin' => false, 'role' => 'listener', 'theme' => 'system', 'access_version' => 0, 'listening_history_enabled' => false, 'listening_recommendations_enabled' => true, 'listening_retention_days' => 30, 'listening_revision' => 0];

    protected $fillable = ['name', 'email', 'password', 'role', 'is_active', 'theme', 'locale', 'direction', 'motion', 'time_zone'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'listening_history_enabled', 'listening_recommendations_enabled', 'listening_retention_days', 'listening_revision', 'listening_private_until', 'listening_consent_at'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'role' => UserRole::class, 'is_admin' => 'boolean', 'is_active' => 'boolean', 'email_verified_at' => 'datetime', 'two_factor_confirmed_at' => 'datetime', 'access_version' => 'integer', 'listening_history_enabled' => 'boolean', 'listening_recommendations_enabled' => 'boolean', 'listening_retention_days' => 'integer', 'listening_revision' => 'integer', 'listening_private_until' => 'immutable_datetime', 'listening_consent_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $user->is_admin = $user->role->isPrivileged();
        });
    }

    public function privateListening(): bool
    {
        return $this->listening_private_until?->isFuture() ?? false;
    }

    public function listeningPreferences(): array
    {
        return ['history_enabled' => $this->listening_history_enabled, 'recommendations_enabled' => $this->listening_recommendations_enabled, 'retention_days' => $this->listening_retention_days, 'private_mode' => $this->privateListening(), 'private_until' => $this->listening_private_until?->toISOString(), 'revision' => $this->listening_revision];
    }

    public function hasPermission(Permission $permission): bool
    {
        return $this->is_active && $this->role->allows($permission);
    }

    public function likedTracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'liked_tracks')->withTimestamps();
    }

    public function savedPlaylists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class, 'saved_playlists')->withTimestamps();
    }
}
