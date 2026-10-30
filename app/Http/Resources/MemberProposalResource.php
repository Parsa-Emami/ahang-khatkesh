<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\Playlist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = ['id' => $this->id, 'kind' => $this->kind, 'status' => $this->status, 'revision' => $this->revision, 'payload' => (object) $this->payload, 'resolution' => $this->resolution, 'review_note' => $this->review_note, 'created_at' => $this->created_at->toISOString(), 'reviewed_at' => $this->reviewed_at?->toISOString()];
        $data['result_playlist_id'] = $this->relationLoaded('resultPlaylist') && $this->resultPlaylist && ! $this->resultPlaylist->trashed() && ($request->user()->hasPermission(Permission::CatalogManage) || $this->resultPlaylist->status === Playlist::PUBLISHED) ? $this->result_playlist_id : null;
        if ($request->user()->hasPermission(Permission::CatalogManage)) {
            $data['submitter'] = ['id' => $this->user_id, 'name' => $this->whenLoaded('submitter', fn () => $this->submitter->name)];
        }

        return $data;
    }
}
