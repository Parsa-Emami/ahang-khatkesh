<?php

namespace App\Http\Resources;

use App\Support\Lyrics\LrcParser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrackLyricResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $parsed = null;
        if ($this->format === 'lrc') {
            $parsed = app(LrcParser::class)->parse($this->body);
        }

        return [
            'track_id' => (int) $this->track_id,
            'format' => $this->format,
            'source' => $this->source,
            'license_note' => $this->license_note,
            'revision' => (int) $this->revision,
            'body' => $this->body,
            'lines' => $parsed['lines'] ?? null,
            'tags' => $parsed['tags'] ?? null,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
