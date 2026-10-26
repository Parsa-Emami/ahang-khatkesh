<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AccountAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_cannot_read_catalog_and_registration_is_closed(): void
    {
        $this->getJson('/api/v1/playlists')->assertUnauthorized()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Request-ID');
        $this->postJson('/api/v1/register', ['email' => 'new@example.com'])->assertNotFound();
    }

    public function test_member_cannot_create_accounts_or_import_media(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member)->postJson('/api/v1/admin/users', [])->assertForbidden();
        $this->actingAs($member)->postJson('/api/v1/admin/imports', [])->assertForbidden();
        $this->actingAs($member)->postJson('/api/v1/admin/media', [])->assertForbidden();
        $this->assertDatabaseCount('playlist_imports', 0);
    }

    public function test_tenth_account_is_created_and_eleventh_is_rejected_even_with_inactive_accounts(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        User::factory()->count(8)->create(['is_active' => false]);
        $this->actingAs($admin)->postJson('/api/v1/admin/users', ['name' => 'Tenth', 'email' => 'tenth@example.com', 'password' => 'StrongPassword123'])->assertCreated()->assertJsonPath('data.email', 'tenth@example.com');
        $this->postJson('/api/v1/admin/users', ['name' => 'Eleventh', 'email' => 'eleventh@example.com', 'password' => 'StrongPassword123'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 10);
        $this->assertDatabaseMissing('users', ['email' => 'eleventh@example.com']);
    }

    public function test_disabled_account_is_blocked_and_admin_cannot_disable_self(): void
    {
        $member = User::factory()->create(['is_active' => false]);
        $this->actingAs($member)->getJson('/api/v1/stats')->assertForbidden();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin)->patchJson('/api/v1/admin/users/'.$admin->id, ['is_active' => false])->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'is_active' => true]);
    }

    public function test_theme_update_does_not_accept_privilege_escalation(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member)->patchJson('/api/v1/me', ['theme' => 'light', 'is_admin' => true])->assertOk()->assertJsonPath('data.theme', 'light')->assertJsonPath('data.is_admin', false);
        $this->assertDatabaseHas('users', ['id' => $member->id, 'is_admin' => false]);
    }

    public function test_password_change_requires_current_password(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member)->putJson('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'NewPassword123456', 'password_confirmation' => 'NewPassword123456'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }

    public function test_login_uses_normalized_email_and_does_not_expose_password(): void
    {
        User::factory()->create(['email' => 'member@example.com', 'password' => 'SecurePassword123']);
        $this->postJson('/api/v1/login', ['email' => 'MEMBER@EXAMPLE.COM', 'password' => 'SecurePassword123'])->assertOk()->assertJsonPath('data.user.email', 'member@example.com')->assertJsonMissingPath('data.user.password')->assertJsonStructure(['data' => ['csrf_token']]);
    }
}
