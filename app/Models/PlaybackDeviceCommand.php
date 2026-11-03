<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlaybackDeviceCommand extends Model
{
    public const TYPES = [
        'pause',
        'play',
        'seek',
        'transfer_offer',
        'transfer_accept',
        'transfer_reject',
        'transfer_fallback',
        'revoke',
        'fence',
    ];

    protected $fillable = [
        'user_id',
        'target_device_id',
        'source_device_id',
        'sequence',
        'lease_generation',
        'type',
        'payload',
        'expires_at',
        'consumed_at',
        'invalidated_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'lease_generation' => 'integer',
            'payload' => 'array',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
            'invalidated_at' => 'immutable_datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(PlaybackDevice::class, 'target_device_id');
    }

    public function isDeliverable(int $generation): bool
    {
        return $this->invalidated_at === null
            && $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->lease_generation === $generation;
    }
}
