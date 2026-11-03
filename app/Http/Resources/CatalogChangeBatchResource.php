<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CatalogChangeBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'plan_hash' => $this->plan_hash,
            'plan' => $this->plan,
            'result' => $this->result,
            'expires_at' => $this->expires_at?->toISOString(),
            'committed_at' => $this->committed_at?->toISOString(),
            'rolled_back_at' => $this->rolled_back_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
