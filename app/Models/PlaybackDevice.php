<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybackDevice extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'device_key',
        'session_binding',
        'name',
        'user_agent',
        'revision',
        'last_seen_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
