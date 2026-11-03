<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberProposal extends Model
{
    use HasUuids;

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const KINDS = [
        'track_metadata',
        'artist_rename',
        'playlist_create',
        'duplicate_report',
        'repair_report',
    ];

    protected $fillable = [
        'proposed_by',
        'kind',
        'status',
        'summary',
        'note',
        'payload',
        'catalog_snapshot',
        'revision',
        'reviewed_by',
        'review_note',
        'accepted_batch_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'catalog_snapshot' => 'array',
            'revision' => 'integer',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function proposer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function acceptedBatch(): BelongsTo
    {
        return $this->belongsTo(CatalogChangeBatch::class, 'accepted_batch_id');
    }
}
