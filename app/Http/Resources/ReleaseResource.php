<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReleaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'type' => $this->type, 'released_on' => $this->released_on?->format('Y-m-d'), 'revision' => (int) $this->revision, 'artists' => ArtistResource::collection($this->whenLoaded('artists')), 'tracks_count' => $this->whenCounted('tracks'), 'tracks' => $this->whenLoaded('tracks', fn () => $this->tracks->map(fn ($t) => ['id' => $t->id, 'title' => $t->title, 'disc_number' => $t->pivot->disc_number, 'track_number' => $t->pivot->track_number]))];
    }
}
