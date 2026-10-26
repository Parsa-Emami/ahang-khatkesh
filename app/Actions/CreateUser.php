<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateUser
{
    public function handle(array $data, ?User $actor = null): User
    {
        return DB::transaction(function () use ($data, $actor): User {
            LibrarySetting::whereKey(1)->increment('lock_version');
            if (User::count() >= LibrarySetting::MAX_USERS) {
                throw ValidationException::withMessages(['email' => 'ظرفیت ۱۰ حساب، با احتساب مدیر و حساب‌های غیرفعال، تکمیل است.']);
            }
            $user = User::create(['name' => $data['name'], 'email' => mb_strtolower(trim($data['email'])), 'password' => $data['password'], 'is_admin' => (bool) ($data['is_admin'] ?? false), 'is_active' => true]);
            AuditEvent::create(['user_id' => $actor?->id, 'action' => 'user.created', 'subject_id' => (string) $user->id]);

            return $user;
        }, 5);
    }
}
