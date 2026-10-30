<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberProposal extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'actor_version', 'idempotency_key', 'payload_hash', 'kind', 'status', 'revision', 'payload', 'snapshot', 'reviewed_by', 'review_note', 'resolution', 'result_playlist_id', 'reviewed_at'];

    protected $hidden = ['snapshot', 'payload_hash', 'actor_version', 'idempotency_key'];

    protected function casts(): array
    {
        return ['actor_version' => 'integer', 'revision' => 'integer', 'payload' => 'array', 'snapshot' => 'array', 'reviewed_at' => 'immutable_datetime'];
    }

    public function resultPlaylist(): BelongsTo
    {
        return $this->belongsTo(Playlist::class, 'result_playlist_id')->withTrashed();
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
