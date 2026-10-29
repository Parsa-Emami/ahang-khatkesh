<?php

namespace App\Http\Resources;

use App\Services\ImportMediaReadiness;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($this->resource->getAttribute('_media_statuses') === null) {
            app(ImportMediaReadiness::class)->load([$this->resource]);
        }

        return ['id' => $this->id, 'name' => $this->name, 'description' => $this->description, 'mood' => $this->mood, 'status' => $this->status, 'rows' => $this->rows, 'report' => collect($this->report)->only(['validated_tracks', 'created_tracks', 'reused_tracks', 'metadata_policy', 'plan_hash', 'plan_version'])->all() + ['media' => (object) $this->resource->getAttribute('_media_statuses')], 'playlist_id' => $this->playlist_id, 'error' => $this->error ? __($this->error) : null, 'execution' => ['generation' => $this->generation, 'recovery_count' => $this->recovery_count, 'queued_at' => $this->queued_at?->toIso8601String(), 'heartbeat_at' => $this->heartbeat_at?->toIso8601String(), 'lease_expires_at' => $this->lease_expires_at?->toIso8601String()], 'created_at' => $this->created_at->toIso8601String()];
    }
}
