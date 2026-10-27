<?php

namespace App\Models;

use App\Enums\Permission;
use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Playlist extends Model
{
    use HasFactory;

    public const PUBLISHED = 'published';

    public const DRAFT = 'draft';

    public const ARCHIVED = 'archived';

    public const MOODS = ['focus', 'night', 'calm', 'energy', 'other'];

    protected $fillable = ['name', 'description', 'mood', 'status', 'cover_path', 'artwork_seed', 'created_by', 'search_text'];

    protected $hidden = ['cover_path'];

    protected static function booted(): void
    {
        static::saving(function (Playlist $p): void {
            $p->search_text = TextNormalizer::normalize($p->name.' '.$p->description);
        });
    }

    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class)->withPivot('position')->orderByPivot('position');
    }

    public function savedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'saved_playlists')->withTimestamps();
    }

    #[Scope]
    protected function visibleTo(Builder $query, User $user): Builder
    {
        return $query->when(! $user->hasPermission(Permission::CatalogManage), fn (Builder $q) => $q->where('status', self::PUBLISHED));
    }
}
