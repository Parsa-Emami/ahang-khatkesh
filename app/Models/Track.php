<?php

namespace App\Models;

use App\Actions\EnsureTrackCatalog;
use App\Enums\Permission;
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

    protected $fillable = ['media_asset_id', 'title', 'artist', 'album', 'search_text', 'catalog_revision', 'primary_release_id'];

    protected function casts(): array
    {
        return ['catalog_revision' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (Track $track): void {
            $track->search_text = TextNormalizer::normalize(implode(' ', [$track->title, $track->artist, $track->album]));
        });
        static::created(fn (Track $track) => app(EnsureTrackCatalog::class)->handle($track));
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class);
    }

    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class)->withPivot('position');
    }

    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class)->withPivot(['role', 'position'])->orderByPivot('position');
    }

    public function releases(): BelongsToMany
    {
        return $this->belongsToMany(Release::class)->withPivot(['disc_number', 'track_number']);
    }

    public function primaryRelease(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'primary_release_id');
    }

    public function mediaVariants(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'track_media')->withPivot(['purpose', 'label']);
    }

    public function likedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'liked_tracks')->withTimestamps();
    }

    #[Scope]
    protected function visibleTo(Builder $query, User $user): Builder
    {
        return $query->when(! $user->hasPermission(Permission::CatalogManage), fn (Builder $q) => $q->whereHas('playlists', fn (Builder $p) => $p->where('status', Playlist::PUBLISHED)));
    }
}
