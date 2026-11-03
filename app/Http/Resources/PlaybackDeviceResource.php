<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlaybackDeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_key' => $this->device_key,
            'name' => $this->name,
            'user_agent' => $this->user_agent,
            'revision' => (int) $this->revision,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'is_active' => $this->revoked_at === null,
        ];
    }
}
