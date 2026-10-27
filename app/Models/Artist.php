<?php

namespace App\Models;

use App\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Artist extends Model
{
    protected $fillable = ['name', 'sort_name', 'description', 'country_code', 'provenance'];

    protected static function booted(): void
    {
        static::saving(fn (Artist $artist) => $artist->search_text = TextNormalizer::normalize($artist->name.' '.$artist->sort_name));
    }

    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class)->withPivot(['role', 'position']);
    }

    public function releases(): BelongsToMany
    {
        return $this->belongsToMany(Release::class)->withPivot('position');
    }
}
