<?php

namespace App\Models;

use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Release extends Model
{
    public const TYPES = ['album', 'single', 'ep', 'compilation'];

    protected $fillable = ['title', 'type', 'released_on', 'provenance'];

    protected function casts(): array
    {
        return ['released_on' => 'date:Y-m-d'];
    }

    protected static function booted(): void
    {
        static::saving(fn (Release $release) => $release->search_text = TextNormalizer::normalize($release->title));
    }

    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class)->withPivot('position')->orderByPivot('position');
    }

    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class)->withPivot(['disc_number', 'track_number'])->orderByPivot('disc_number')->orderByPivot('track_number');
    }
}
