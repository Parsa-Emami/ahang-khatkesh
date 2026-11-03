<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'summary' => $this->summary,
            'note' => $this->note,
            'payload' => $this->payload,
            'revision' => (int) $this->revision,
            'proposed_by' => $this->whenLoaded('proposer', fn () => [
                'id' => $this->proposer->id,
                'name' => $this->proposer->name,
            ]),
            'review_note' => $this->review_note,
            'accepted_batch_id' => $this->accepted_batch_id,
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
