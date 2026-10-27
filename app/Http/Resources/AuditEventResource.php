<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class AuditEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'actor' => $this->actor ? ['id' => $this->actor->id, 'name' => $this->actor->name] : null, 'action' => $this->action, 'subject_type' => $this->subject_type, 'subject_id' => $this->subject_id, 'request_id' => $this->request_id, 'metadata' => (object) Arr::only($this->metadata ?? [], ['role_before', 'role_after', 'is_active_before', 'is_active_after', 'playlist_id', 'tracks', 'revision']), 'created_at' => $this->created_at->toIso8601String()];
    }
}
