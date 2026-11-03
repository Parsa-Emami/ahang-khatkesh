<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OfflineDownloadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'track_id' => (int) $this->track_id,
            'asset_sha256' => $this->asset_sha256,
            'bytes' => (int) $this->bytes,
            'status' => $this->status,
            'granted_at' => $this->granted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
        ];
    }
}
