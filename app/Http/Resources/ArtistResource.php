<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArtistResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'sort_name' => $this->sort_name, 'description' => $this->description, 'country_code' => $this->country_code, 'revision' => (int) $this->revision, 'tracks_count' => $this->whenCounted('tracks'), 'needs_review' => $this->provenance !== null];
    }
}
