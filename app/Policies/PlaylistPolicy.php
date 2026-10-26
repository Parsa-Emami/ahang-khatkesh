<?php

namespace App\Policies;

use App\Models\Playlist;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class PlaylistPolicy
{
    public function view(User $user, Playlist $playlist): Response
    {
        return $user->is_active && ($user->is_admin || $playlist->status === Playlist::PUBLISHED) ? Response::allow() : Response::denyAsNotFound();
    }
}
