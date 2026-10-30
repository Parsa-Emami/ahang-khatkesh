<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Track;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class TrackPolicy
{
    public function view(User $user, Track $track): Response
    {
        $root = $track->merged_into_id ? $track->mergedInto : $track;

        return $root && ! $root->trashed() && $root->merged_into_id === null && $user->is_active && ! $track->trashed() && ($user->hasPermission(Permission::CatalogManage) || $root->playlists()->where('status', 'published')->exists()) ? Response::allow() : Response::denyAsNotFound();
    }
}
