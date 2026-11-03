<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlaybackDeviceCommandResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => (int) $this->sequence,
            'lease_generation' => (int) $this->lease_generation,
            'type' => $this->type,
            'payload' => $this->payload ?? (object) [],
            'expires_at' => $this->expires_at?->toIso8601String(),
            'source_device_id' => $this->source_device_id,
        ];
    }
}
