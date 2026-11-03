<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogChangeBatch extends Model
{
    use HasUuids;

    public const PREVIEWED = 'previewed';

    public const COMMITTED = 'committed';

    public const ROLLED_BACK = 'rolled_back';

    public const EXPIRED = 'expired';

    public const KINDS = ['track_metadata', 'artist_rename', 'track_move', 'merge_recordings', 'repair'];

    protected $fillable = ['created_by', 'kind', 'status', 'plan_hash', 'plan', 'snapshot', 'result', 'expires_at', 'committed_at', 'rolled_back_at'];

    protected function casts(): array
    {
        return [
            'plan' => 'array',
            'snapshot' => 'array',
            'result' => 'array',
            'expires_at' => 'immutable_datetime',
            'committed_at' => 'immutable_datetime',
            'rolled_back_at' => 'immutable_datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPreviewAlive(): bool
    {
        return $this->status === self::PREVIEWED && $this->expires_at->isFuture();
    }
}
