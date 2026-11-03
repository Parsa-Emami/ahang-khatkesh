<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlaybackLeaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'device_id' => $this->device_id,
            'generation' => (int) $this->generation,
            'lease_token' => $this->lease_token,
            'state' => $this->state,
            'revision' => (int) $this->revision,
            'transfer_target_id' => $this->transfer_target_id,
            'transfer_expires_at' => $this->transfer_expires_at?->toIso8601String(),
            'heartbeat_at' => $this->heartbeat_at?->toIso8601String(),
            'lease_expires_at' => $this->lease_expires_at?->toIso8601String(),
            'device' => $this->whenLoaded('device', fn () => new PlaybackDeviceResource($this->device)),
            'transfer_target' => $this->whenLoaded('transferTarget', fn () => $this->transferTarget ? new PlaybackDeviceResource($this->transferTarget) : null),
        ];
    }
}
