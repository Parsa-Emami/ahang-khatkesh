<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PlaylistPolicy
{
    public function view(User $user, Playlist $playlist): Response
    {
        return $user->is_active && ! $playlist->trashed() && ($user->hasPermission(Permission::CatalogManage) || $playlist->status === Playlist::PUBLISHED) ? Response::allow() : Response::denyAsNotFound();
    }
}
