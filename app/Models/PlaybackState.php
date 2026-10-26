<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlaybackState extends Model
{
    protected $fillable = ['user_id', 'track_id', 'queue', 'position_seconds', 'volume', 'shuffle', 'repeat_mode', 'revision'];

    protected function casts(): array
    {
        return ['queue' => 'array', 'position_seconds' => 'float', 'volume' => 'float', 'shuffle' => 'boolean', 'revision' => 'integer'];
    }
}
