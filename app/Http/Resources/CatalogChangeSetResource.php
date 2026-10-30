<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogChangeSetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['can_commit' => $this->status === 'previewed' && $this->expires_at->isFuture(), 'can_rollback' => $this->status === 'committed' && $this->committed_at->addDays(config('catalog.rollback_days'))->isFuture(), 'id' => $this->id, 'operation' => $this->operation, 'status' => $this->status, 'revision' => $this->revision, 'snapshot_hash' => $this->snapshot_hash, 'summary' => array_map(fn ($row) => array_intersect_key($row, array_flip(['entity', 'id', 'before', 'after'])), $this->summary), 'result' => (object) ($this->result ?? []), 'proposal_id' => $this->proposal_id, 'expires_at' => $this->expires_at->toISOString(), 'created_at' => $this->created_at->toISOString(), 'committed_at' => $this->committed_at?->toISOString(), 'rolled_back_at' => $this->rolled_back_at?->toISOString(), 'rollback_until' => $this->committed_at?->addDays(config('catalog.rollback_days'))->toISOString()];
    }
}
