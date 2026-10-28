<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PlaylistImport extends Model
{
    use HasUuids;

    public const READY = 'ready';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const NEEDS_REVIEW = 'needs_review';

    public const CANCELLED = 'cancelled';

    protected $fillable = ['created_by', 'idempotency_key', 'payload_hash', 'name', 'description', 'mood', 'rows', 'status', 'report', 'playlist_id', 'publish', 'error', 'generation'];

    protected function casts(): array
    {
        return ['generation' => 'integer', 'rows' => 'array', 'report' => 'array', 'publish' => 'boolean'];
    }
}
