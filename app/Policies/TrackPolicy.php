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
        return $user->is_active && ($user->hasPermission(Permission::CatalogManage) || $track->playlists()->where('status', 'published')->exists()) ? Response::allow() : Response::denyAsNotFound();
    }
}
