<?php

namespace App\Actions;

use App\Models\LibrarySetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class LockCurrentAccount
{
    public function handle(User $snapshot, ?int $presentedVersion = null): User
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Account writes require a database transaction.');
        }
        LibrarySetting::whereKey(1)->increment('lock_version');
        $current = User::whereKey($snapshot->id)->lockForUpdate()->first();
        abort_unless($current && $current->is_active && $current->access_version === $snapshot->access_version && ($presentedVersion === null || $current->access_version === $presentedVersion), 401, 'نشست تغییر کرده است؛ دوباره وارد شوید.');

        return $current;
    }
}
