<?php

namespace App\Http\Resources;

use App\Services\PlaybackMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'title' => $this->title, 'artist' => $this->artist, 'album' => $this->album, 'primary_release_id' => $this->primary_release_id, 'revision' => (int) $this->catalog_revision, 'artists' => $this->whenLoaded('artists', fn () => $this->artists->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'role' => $a->pivot->role])), 'releases' => $this->whenLoaded('releases', fn () => $this->releases->map(fn ($r) => ['id' => $r->id, 'title' => $r->title])), 'duration_seconds' => $this->mediaAsset->duration_seconds, 'audio_qualities' => app(PlaybackMedia::class)->qualities($this->resource), 'stream_url' => route('api.tracks.stream', ['track' => $this->id], false), 'is_liked' => (bool) ($this->is_liked ?? false)];
    }
}
