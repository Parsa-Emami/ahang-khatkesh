<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'artist' => $this->artist, 'album' => $this->album, 'duration_seconds' => $this->mediaAsset->duration_seconds, 'stream_url' => route('api.tracks.stream', ['track' => $this->id], false), 'is_liked' => (bool) ($this->is_liked ?? false)];
    }
}
