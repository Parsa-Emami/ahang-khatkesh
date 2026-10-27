<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlaylistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'description' => $this->description, 'mood' => $this->mood, 'status' => $this->status, 'revision' => (int) $this->revision, 'artwork_seed' => (int) $this->artwork_seed, 'cover_url' => $this->cover_path ? route('api.playlists.cover', ['playlist' => $this->id], false).'?v='.$this->updated_at->timestamp : null, 'tracks_count' => $this->whenCounted('tracks'), 'is_saved' => (bool) ($this->is_saved ?? false), 'tracks' => TrackResource::collection($this->whenLoaded('tracks'))];
    }
}
