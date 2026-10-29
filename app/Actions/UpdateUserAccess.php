<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateUserAccess
{
    public function __construct(private LockCurrentAccount $accounts) {}

    public function handle(User $subject, User $actor, array $data): User
    {
        return DB::transaction(function () use ($subject, $actor, $data): User {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $actor = $this->accounts->handle($actor);
            $subject = User::whereKey($subject->id)->lockForUpdate()->firstOrFail();
            abort_unless($actor->hasPermission(Permission::UsersManage), 403);
            $role = isset($data['role']) ? UserRole::from($data['role']) : $subject->role;
            $active = (bool) ($data['is_active'] ?? $subject->is_active);
            if ($actor->id === $subject->id && ! $active) {
                throw ValidationException::withMessages(['is_active' => __('نمی‌توانید حساب خودتان را غیرفعال کنید.')]);
            }
            abort_if(! $actor->hasPermission(Permission::RolesManage) && ($subject->role->isPrivileged() || $role->isPrivileged()), 403);
            if ($subject->role === UserRole::Owner && ($role !== UserRole::Owner || ! $active) && ! User::where('role', UserRole::Owner)->where('is_active', true)->where('id', '!=', $subject->id)->exists()) {
                throw ValidationException::withMessages(['role' => __('ابتدا یک مالک فعال دیگر تعیین کنید؛ آخرین مالک فعال قابل تنزل نیست.')]);
            }
            $before = ['role_before' => $subject->role->value, 'is_active_before' => $subject->is_active];
            $subject->fill(['role' => $role, 'is_active' => $active]);
            if ($subject->isDirty(['role', 'is_active'])) {
                $subject->access_version++;
                $subject->save();
                if (config('session.driver') === 'database') {
                    DB::table('sessions')->where('user_id', $subject->id)->delete();
                }
                AuditEvent::create(['user_id' => $actor->id, 'action' => 'user.access_changed', 'subject_id' => (string) $subject->id, 'metadata' => [...$before, 'role_after' => $role->value, 'is_active_after' => $active]]);
            }

            return $subject;
        }, 5);
    }
}
