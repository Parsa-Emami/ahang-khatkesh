<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class MfaFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_password_alone_does_not_authorize_a_two_factor_account(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'OwnerPassword123']);
        $this->postJson('/api/v1/login', ['email' => $owner->email, 'password' => 'OwnerPassword123'])
            ->assertOk()->assertJsonPath('data.two_factor_required', true);
        $this->assertGuest();
        $this->getJson('/api/v1/admin/users')->assertUnauthorized();
        $code = (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP');
        $this->postJson('/api/v1/login/challenge', ['code' => $code])
            ->assertOk()->assertJsonPath('data.user.mfa_enabled', true)->assertJsonMissingPath('data.user.two_factor_secret');
        $this->getJson('/api/v1/admin/users')->assertOk();
    }

    public function test_recovery_code_is_consumed_once(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'OwnerPassword123']);
        $login = ['email' => $owner->email, 'password' => 'OwnerPassword123'];
        $this->postJson('/api/v1/login', $login)->assertOk();
        $this->postJson('/api/v1/login/challenge', ['recovery_code' => 'test-recovery-code-1'])->assertOk();
        $this->assertNotContains('test-recovery-code-1', $owner->fresh()->recoveryCodes());
        $this->postJson('/api/v1/logout')->assertOk();
        $this->postJson('/api/v1/login', $login)->assertOk();
        $this->postJson('/api/v1/login/challenge', ['recovery_code' => 'test-recovery-code-1'])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_disabling_an_account_invalidates_its_pending_challenge(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Owner, 'password' => 'OwnerPassword123']);
        $this->postJson('/api/v1/login', ['email' => $owner->email, 'password' => 'OwnerPassword123'])->assertOk();
        $owner->update(['is_active' => false]);
        $this->postJson('/api/v1/login/challenge', ['recovery_code' => 'test-recovery-code-1'])
            ->assertUnprocessable()->assertJsonPath('code', 'challenge_expired');
        $this->assertGuest();
    }

    public function test_admin_enrollment_requires_a_valid_code_and_encrypts_secrets(): void
    {
        $owner = User::factory()->withoutMfa()->create(['role' => UserRole::Owner, 'password' => 'OwnerPassword123']);
        $this->postJson('/api/v1/login', ['email' => $owner->email, 'password' => 'OwnerPassword123'])->assertOk();
        $this->getJson('/api/v1/admin/overview')->assertStatus(428)->assertJsonPath('code', 'mfa_enrollment_required');
        $setup = $this->postJson('/api/v1/me/mfa')->assertOk();
        $secret = $setup->json('data.setup_key');
        $this->assertNotSame($secret, $owner->fresh()->two_factor_secret);
        $this->assertFalse($owner->fresh()->hasEnabledTwoFactorAuthentication());
        $this->postJson('/api/v1/me/mfa/confirm', ['code' => '000000'])->assertUnprocessable();
        $code = (new Google2FA)->getCurrentOtp($secret);
        $this->postJson('/api/v1/me/mfa/confirm', ['code' => $code])->assertOk()->assertJsonCount(8, 'data.recovery_codes');
        $this->assertTrue($owner->fresh()->hasEnabledTwoFactorAuthentication());
        $this->getJson('/api/v1/admin/overview')->assertOk();
        $this->deleteJson('/api/v1/me/mfa')->assertUnprocessable();
    }

    public function test_authenticated_login_requests_return_json_conflict_without_switching_accounts(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member)->postJson('/api/v1/login', ['email' => 'different@example.test', 'password' => 'OtherPassword123'])->assertConflict()->assertJsonPath('code', 'already_authenticated');
        $this->assertAuthenticatedAs($member);
    }
}
