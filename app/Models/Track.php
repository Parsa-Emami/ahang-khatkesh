<?php

namespace App\Models;

use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Track extends Model
{
    use HasFactory;

    protected $fillable = ['media_asset_id', 'title', 'artist', 'album', 'search_text'];

    protected static function booted(): void
    {
        static::saving(function (Track $track): void {
            $track->search_text = TextNormalizer::normalize(implode(' ', [$track->title, $track->artist, $track->album]));
        });
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class)->withPivot('position');
    }

    public function likedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'liked_tracks')->withTimestamps();
    }

    #[Scope]
    protected function visibleTo(Builder $query, User $user): Builder
    {
        return $query->when(! $user->is_admin, fn (Builder $q) => $q->whereHas('playlists', fn (Builder $p) => $p->where('status', Playlist::PUBLISHED)));
    }
}
