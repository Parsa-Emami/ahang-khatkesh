<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResetMemberPassword
{
    public function __construct(private LockCurrentAccount $accounts) {}

    public function handle(User $subject, User $actor, string $password): void
    {
        $encoded = Hash::make($password);
        DB::transaction(function () use ($subject, $actor, $encoded): void {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $actor = $this->accounts->handle($actor);
            $subject = User::whereKey($subject->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->hasPermission(Permission::UsersManage), 403);
            abort_if($subject->role->isPrivileged() && ! $actor->hasPermission(Permission::RolesManage), 403);
            abort_if($actor->id === $subject->id, 422, __('برای تغییر رمز خودتان از تنظیمات حساب استفاده کنید.'));
            $subject->forceFill(['password' => $encoded, 'access_version' => $subject->access_version + 1, 'remember_token' => Str::random(60)])->save();
            if (config('session.driver') === 'database') {
                DB::table('sessions')->where('user_id', $subject->id)->delete();
            }
            AuditEvent::create(['user_id' => $actor->id, 'action' => 'user.password_reset', 'subject_id' => (string) $subject->id]);
        }, 5);
    }
}
