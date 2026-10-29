<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ListeningEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'track' => new TrackResource($this->track), 'started_at' => $this->started_at->toISOString(), 'ended_at' => $this->ended_at?->toISOString(), 'listened_seconds' => $this->listened_seconds, 'outcome' => $this->outcome];
    }
}
