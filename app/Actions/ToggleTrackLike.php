<?php

namespace App\Actions;

use App\Models\Track;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ToggleTrackLike
{
    public function __construct(private LockCurrentAccount $accounts) {}

    public function handle(User $snapshot, Track $track, bool $liked): void
    {
        DB::transaction(function () use ($snapshot, $track, $liked): void {
            $user = $this->accounts->handle($snapshot);
            $current = Track::findOrFail($track->id);
            Gate::forUser($user)->authorize('view', $current);
            $root = $current->merged_into_id ?? $current->id;
            if ($liked) {
                DB::table('liked_tracks')->insertOrIgnore(['user_id' => $user->id, 'track_id' => $root, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                $ids = Track::withTrashed()->where(fn ($query) => $query->whereKey($root)->orWhere('merged_into_id', $root))->pluck('id');
                DB::table('liked_tracks')->where('user_id', $user->id)->whereIn('track_id', $ids)->delete();
            }
        }, 5);
    }
}
