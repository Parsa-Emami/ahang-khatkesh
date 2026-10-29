<?php

namespace App\Services\Listening;

use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ListeningCatalog
{
    public function query(User $user): Builder
    {
        return Track::visibleTo($user)->whereHas('mediaAsset', fn (Builder $query) => $query->where('duration_seconds', '>', 0)->where(fn (Builder $blob) => $blob->whereNull('blob_id')->orWhereHas('blob', fn (Builder $ready) => $ready->where('state', 'ready'))));
    }
}
