<?php

namespace Tests\Feature;

use App\Actions\ResetMemberPassword;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CredentialEpochTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_cached_self_password_change_cannot_overwrite_a_newer_administrator_reset(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $snapshot = User::factory()->create(['password' => 'OldPassword123']);
        $this->actingAs($snapshot)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        app(ResetMemberPassword::class)->handle($snapshot, $owner, 'ResetPassword123');
        $this->putJson('/api/v1/me/password', ['current_password' => 'OldPassword123', 'password' => 'StaleAttempt123', 'password_confirmation' => 'StaleAttempt123'])->assertUnauthorized();
        $fresh = $snapshot->fresh();
        $this->assertSame(1, $fresh->access_version);
        $this->assertTrue(Hash::check('ResetPassword123', $fresh->password));
        $this->assertDatabaseMissing('audit_events', ['action' => 'auth.password_changed']);
    }

    public function test_valid_changes_use_successive_fresh_epochs_and_return_current_identity(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword123']);
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->putJson('/api/v1/me/password', ['current_password' => 'OldPassword123', 'password' => 'NextPassword123', 'password_confirmation' => 'NextPassword123'])->assertOk()->assertJsonPath('data.user.access_version', 1);
        $this->putJson('/api/v1/me/password', ['current_password' => 'NextPassword123', 'password' => 'ThirdPassword123', 'password_confirmation' => 'ThirdPassword123'])->assertOk()->assertJsonPath('data.user.access_version', 2);
        $this->assertSame(2, $user->fresh()->access_version);
        $this->assertTrue(Hash::check('ThirdPassword123', $user->fresh()->password));
    }

    public function test_wrong_password_creates_no_change_or_audit(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword123']);
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->putJson('/api/v1/me/password', ['current_password' => 'WrongPassword123', 'password' => 'NextPassword123', 'password_confirmation' => 'NextPassword123'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertSame(0, $user->fresh()->access_version);
        $this->assertDatabaseMissing('audit_events', ['action' => 'auth.password_changed']);
    }

    public function test_stale_password_confirmation_cannot_refresh_the_session(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $snapshot = User::factory()->create(['password' => 'OldPassword123']);
        $this->actingAs($snapshot);
        app(ResetMemberPassword::class)->handle($snapshot, $owner, 'ResetPassword123');
        $this->postJson('/api/v1/me/password/confirm', ['password' => 'OldPassword123'])->assertUnauthorized();
        $this->assertDatabaseMissing('audit_events', ['action' => 'auth.password_confirmed']);
    }
}
