<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaConversion extends Model
{
    use HasUuids;

    public const ACTIVE = ['queued', 'processing', 'validating'];

    protected $guarded = [];

    protected $attributes = ['generation' => 0, 'revision' => 1, 'automatic_retries' => 0];

    protected $hidden = ['lease_token', 'ownership_hash'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'source_asset_id');
    }

    public function output(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'output_asset_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(MediaConversionAttempt::class, 'conversion_id');
    }

    protected function casts(): array
    {
        return ['generation' => 'integer', 'revision' => 'integer', 'source_revision' => 'integer', 'actor_epoch' => 'integer', 'profile_version' => 'integer', 'automatic_retries' => 'integer', 'reserved_bytes' => 'integer', 'progress_seconds' => 'float', 'report' => 'array', 'lease_expires_at' => 'datetime', 'heartbeat_at' => 'datetime', 'queued_at' => 'datetime', 'next_retry_at' => 'datetime'];
    }
}
