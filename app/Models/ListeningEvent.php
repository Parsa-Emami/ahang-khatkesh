<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListeningEvent extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'user_id', 'track_id', 'session_pseudonym', 'started_at', 'last_event_at', 'ended_at', 'listened_seconds', 'outcome'];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'last_event_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'listened_seconds' => 'float'];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
