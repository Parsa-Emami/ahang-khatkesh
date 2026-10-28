<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaGarbageCandidate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'eligible_at' => 'immutable_datetime'];
    }
}
