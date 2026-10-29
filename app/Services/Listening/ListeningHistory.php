<?php

namespace App\Services\Listening;

use App\Models\ListeningEvent;
use App\Models\User;
use App\Services\PlaybackMedia;
use Illuminate\Database\Eloquent\Builder;

class ListeningHistory
{
    public function __construct(private ListeningCatalog $catalog) {}

    public function query(User $user): Builder
    {
        return ListeningEvent::where('user_id', $user->id)->when(! $user->listening_history_enabled, fn (Builder $query) => $query->whereRaw('1 = 0'))->where('started_at', '>', now()->subDays($user->listening_retention_days))->whereIn('track_id', $this->catalog->query($user)->select('tracks.id'));
    }

    public function eager(User $user): Builder
    {
        return $this->query($user)->with(['track' => fn ($query) => $query->with(['mediaAsset.blob', PlaybackMedia::EAGER])->withExists(['likedBy as is_liked' => fn (Builder $liked) => $liked->where('users.id', $user->id)])])->orderByDesc('started_at')->orderByDesc('id');
    }
}
