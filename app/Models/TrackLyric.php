<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackLyric extends Model
{
    public const FORMATS = ['plain', 'lrc'];

    public const SOURCES = ['manual', 'licensed'];

    protected $fillable = ['track_id', 'body', 'format', 'source', 'license_note', 'revision', 'updated_by'];

    protected function casts(): array
    {
        return ['revision' => 'integer'];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
