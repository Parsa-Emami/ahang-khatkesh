<?php

namespace App\Actions;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChangeOwnPassword
{
    public function __construct(private LockCurrentAccount $accounts) {}

    public function handle(User $snapshot, int $presentedVersion, string $currentPassword, string $password, string $sessionId): User
    {
        $observed = User::findOrFail($snapshot->id);
        abort_unless($observed->is_active && $observed->access_version === $snapshot->access_version && $observed->access_version === $presentedVersion, 401);
        if (! Hash::check($currentPassword, $observed->password)) {
            throw ValidationException::withMessages(['current_password' => 'رمز فعلی درست نیست.']);
        }
        $encoded = Hash::make($password);

        return DB::transaction(function () use ($snapshot, $presentedVersion, $observed, $encoded, $sessionId): User {
            $current = $this->accounts->handle($snapshot, $presentedVersion);
            abort_unless(hash_equals($observed->password, $current->password), 401);
            $current->forceFill(['password' => $encoded, 'access_version' => $current->access_version + 1, 'remember_token' => Str::random(60)])->save();
            if (config('session.driver') === 'database') {
                DB::table('sessions')->where('user_id', $current->id)->where('id', '!=', $sessionId)->delete();
            }
            AuditEvent::create(['user_id' => $current->id, 'action' => 'auth.password_changed', 'subject_id' => (string) $current->id]);

            return $current;
        }, 5);
    }
}
