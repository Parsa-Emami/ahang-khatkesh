<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $attributes = ['is_active' => true, 'is_admin' => false, 'theme' => 'system'];

    protected $fillable = ['name', 'email', 'password', 'is_admin', 'is_active', 'theme'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_admin' => 'boolean', 'is_active' => 'boolean', 'email_verified_at' => 'datetime'];
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
