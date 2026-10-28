<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\User;

class AuthorizeLibraryWrite
{
    public function __construct(private LockCurrentAccount $accounts) {}

    public function handle(User $snapshot): User
    {
        $current = $this->accounts->handle($snapshot);
        abort_unless($current->role === UserRole::Owner, 403);

        return $current;
    }
}
