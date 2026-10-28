<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'description' => $this->description, 'mood' => $this->mood, 'status' => $this->status, 'rows' => $this->rows, 'report' => collect($this->report)->only(['validated_tracks', 'created_tracks', 'reused_tracks', 'metadata_policy', 'plan_hash', 'plan_version'])->all(), 'playlist_id' => $this->playlist_id, 'error' => $this->error, 'created_at' => $this->created_at->toIso8601String()];
    }
}
