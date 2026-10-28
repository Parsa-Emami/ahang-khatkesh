<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'source_asset_id' => $this->source_asset_id, 'source_name' => $this->source->original_name, 'source_revision' => $this->source->ownership_revision, 'source_bytes' => $this->source->size_bytes, 'duration_seconds' => $this->source->duration_seconds, 'profile_id' => $this->profile_id, 'profile_version' => $this->profile_version, 'status' => $this->status, 'generation' => $this->generation, 'revision' => $this->revision, 'progress_seconds' => $this->progress_seconds, 'progress_percent' => $this->status === 'ready' ? 100 : min(99, (int) floor($this->progress_seconds / max(0.001, $this->source->duration_seconds) * 100)), 'reserved_bytes' => $this->reserved_bytes, 'output_asset_id' => $this->output_asset_id, 'output_bytes' => $this->output?->size_bytes, 'error_code' => $this->error_code, 'next_retry_at' => $this->next_retry_at?->toIso8601String(), 'heartbeat_at' => $this->heartbeat_at?->toIso8601String(), 'lease_expires_at' => $this->lease_expires_at?->toIso8601String(), 'report' => $this->report === null ? null : collect($this->report)->only(['bitrate_bps', 'validated_frames', 'validated_source_samples', 'full_source_decode', 'full_decode', 'encoder_version', 'encoder_sha256', 'probe_sha256', 'profile_version', 'source_retained', 'output_bytes', 'source_bytes'])->all(), 'created_at' => $this->created_at->toIso8601String()];
    }
}
