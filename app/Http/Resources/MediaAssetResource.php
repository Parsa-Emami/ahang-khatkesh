<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $c = $this->conversions->first();

        return ['id' => $this->id, 'original_name' => $this->original_name, 'duration_seconds' => $this->duration_seconds, 'size_bytes' => $this->size_bytes, 'codec' => $this->codec, 'sample_rate' => $this->sample_rate, 'channels' => $this->channels, 'revision' => $this->ownership_revision, 'conversion' => $c ? ['id' => $c->id, 'status' => $c->status] : null];
    }
}
