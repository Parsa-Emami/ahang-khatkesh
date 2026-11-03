<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineDownload extends Model
{
    public const PENDING = 'pending';

    public const READY = 'ready';

    public const REVOKED = 'revoked';

    protected $fillable = [
        'user_id',
        'track_id',
        'asset_sha256',
        'bytes',
        'status',
        'granted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'granted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
