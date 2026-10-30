<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CatalogChangeSet extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'actor_version', 'operation', 'status', 'revision', 'snapshot_hash', 'input', 'scope', 'before', 'after', 'summary', 'result', 'proposal_id', 'expires_at', 'committed_at', 'rolled_back_at'];

    protected $hidden = ['input', 'scope', 'before', 'after', 'actor_version'];

    protected function casts(): array
    {
        return ['actor_version' => 'integer', 'revision' => 'integer', 'input' => 'array', 'scope' => 'array', 'before' => 'array', 'after' => 'array', 'summary' => 'array', 'result' => 'array', 'expires_at' => 'immutable_datetime', 'committed_at' => 'immutable_datetime', 'rolled_back_at' => 'immutable_datetime'];
    }
}
