<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\LibrarySetting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecoverOwner extends Command
{
    protected $signature = 'app:recover-owner {email} {--reset-mfa} {--force}';

    protected $description = 'Recover an existing owner from the trusted server console';

    public function handle(): int
    {
        $email = mb_strtolower(trim($this->argument('email')));
        if (! User::where('email', $email)->exists()) {
            $this->error('Account not found.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('Grant owner access, activate this account and revoke its sessions?')) {
            return self::FAILURE;
        }
        DB::transaction(function () use ($email): void {
            LibrarySetting::whereKey(1)->increment('lock_version');
            $user = User::where('email', $email)->lockForUpdate()->firstOrFail();
            $before = ['role_before' => $user->role->value, 'is_active_before' => $user->is_active];
            $user->forceFill(['role' => UserRole::Owner, 'is_active' => true, 'access_version' => $user->access_version + 1, 'remember_token' => Str::random(60)]);
            if ($this->option('reset-mfa')) {
                $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null]);
            }
            $user->save();
            if (config('session.driver') === 'database') {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            AuditEvent::create(['action' => 'user.owner_recovered', 'subject_id' => (string) $user->id, 'metadata' => [...$before, 'role_after' => 'owner', 'is_active_after' => true]]);
        }, 5);
        $this->info('Owner recovered. Sign in again and complete MFA enrollment if reset.');

        return self::SUCCESS;
    }
}
