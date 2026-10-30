<?php

namespace App\Models;

use App\Enums\Permission;
use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Track extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['media_asset_id', 'title', 'artist', 'album', 'search_text', 'catalog_revision', 'primary_release_id', 'recording_identity', 'recording_provenance', 'merged_into_id'];

    protected function casts(): array
    {
        return ['catalog_revision' => 'integer', 'merged_into_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (Track $track): void {
            $track->search_text = TextNormalizer::normalize(implode(' ', [$track->title, $track->artist, $track->album]));
        });
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_id');
    }

    #[Scope]
    protected function canonical(Builder $query): Builder
    {
        return $query->whereNull('tracks.merged_into_id');
    }

    private function userLikeQuery(User $user): \Illuminate\Database\Query\Builder
    {
        return DB::table('liked_tracks as own_likes')->join('tracks as liked_recording', 'liked_recording.id', '=', 'own_likes.track_id')->where('own_likes.user_id', $user->id)->whereNull('liked_recording.deleted_at')->whereRaw('COALESCE(liked_recording.merged_into_id, liked_recording.id) = COALESCE(tracks.merged_into_id, tracks.id)');
    }

    #[Scope]
    protected function withUserLike(Builder $query, User $user): Builder
    {
        return $query->addSelect('tracks.*')->selectSub($this->userLikeQuery($user)->selectRaw('1')->limit(1), 'is_liked');
    }

    #[Scope]
    protected function likedFor(Builder $query, User $user): Builder
    {
        return $query->whereExists($this->userLikeQuery($user)->selectRaw('1'));
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
        return $query->where(fn (Builder $q) => $q->whereNull('tracks.merged_into_id')->orWhereHas('mergedInto', fn (Builder $root) => $root->whereNull('merged_into_id')))->when(! $user->hasPermission(Permission::CatalogManage), fn (Builder $q) => $q->where(fn (Builder $visible) => $visible->where(fn (Builder $root) => $root->whereNull('tracks.merged_into_id')->whereHas('playlists', fn (Builder $p) => $p->where('status', Playlist::PUBLISHED)))->orWhereHas('mergedInto.playlists', fn (Builder $p) => $p->where('status', Playlist::PUBLISHED))));
    }
}
