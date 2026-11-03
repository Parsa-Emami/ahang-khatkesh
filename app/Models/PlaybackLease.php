<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybackLease extends Model
{
    public const IDLE = 'idle';

    public const ACTIVE = 'active';

    public const TRANSFERRING = 'transferring';

    protected $fillable = [
        'user_id',
        'device_id',
        'generation',
        'lease_token',
        'state',
        'revision',
        'transfer_target_id',
        'transfer_expires_at',
        'heartbeat_at',
        'lease_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'revision' => 'integer',
            'transfer_expires_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(PlaybackDevice::class, 'device_id');
    }

    public function transferTarget(): BelongsTo
    {
        return $this->belongsTo(PlaybackDevice::class, 'transfer_target_id');
    }

    public function isHeldBy(string $deviceId, string $token): bool
    {
        return in_array($this->state, [self::ACTIVE, self::TRANSFERRING], true)
            && $this->device_id === $deviceId
            && $this->lease_token === $token
            && $this->lease_expires_at !== null
            && $this->lease_expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->lease_expires_at === null || $this->lease_expires_at->isPast();
    }
}
