<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateUser
{
    public function __construct(private LockCurrentAccount $accounts) {}

    public function handle(array $data, ?User $actor = null): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $role = isset($data['role']) ? UserRole::from($data['role']) : (($data['is_admin'] ?? false) ? UserRole::Administrator : UserRole::Listener);
            if ($actor !== null) {
                $actor = $this->accounts->handle($actor);
                abort_unless($actor->hasPermission(Permission::UsersManage), 403);
                abort_if($role->isPrivileged() && ! $actor->hasPermission(Permission::RolesManage), 403);
            } elseif ($role === UserRole::Administrator && ! User::where('role', UserRole::Owner)->where('is_active', true)->exists()) {
                $role = UserRole::Owner;
            }
            if (User::count() >= LibrarySetting::MAX_USERS) {
                throw ValidationException::withMessages(['email' => __('ظرفیت ۱۰ حساب، با احتساب مدیر و حساب‌های غیرفعال، تکمیل است.')]);
            }
            $user = User::create(['name' => $data['name'], 'email' => mb_strtolower(trim($data['email'])), 'password' => $data['password'], 'role' => $role, 'is_active' => true]);
            AuditEvent::create(['user_id' => $actor?->id, 'action' => 'user.created', 'subject_id' => (string) $user->id, 'metadata' => ['role_after' => $role->value]]);

            return $user;
        }, 5);
    }
}
